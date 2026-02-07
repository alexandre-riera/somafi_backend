<?php

namespace App\Command\Kizeo;

use App\DTO\Kizeo\ExtractedMedia;
use App\Repository\KizeoJobRepository;
use App\Service\Kizeo\KizeoApiService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de téléchargement des photos d'équipements depuis l'API Kizeo
 * 
 * Consomme les jobs de type 'photo' dans la table kizeo_jobs.
 * Même pattern que DownloadPdfCommand : chunks, gestion mémoire, retry.
 * 
 * Endpoint API : GET /forms/{formId}/data/{dataId}/medias/{mediaName}
 * Stockage     : storage/img/{agency}/{id_contact}/{annee}/{visite}/{filename}
 * 
 * Usage:
 *   php bin/console app:kizeo:download-media                          # 200 jobs par défaut
 *   php bin/console app:kizeo:download-media --limit=500 --chunk=20   # 500 jobs, chunks de 20
 *   php bin/console app:kizeo:download-media --agency=S100            # Une seule agence
 *   php bin/console app:kizeo:download-media --dry-run                # Simulation
 *   php bin/console app:kizeo:download-media --retry-failed           # Relancer les failed
 * 
 * Recommandation locale (rattrapage historique) :
 *   php -d memory_limit=2G bin/console app:kizeo:download-media --limit=5000 --chunk=50 -v
 * 
 * CRON o2switch (production) :
 *   php bin/console app:kizeo:download-media --limit=100 --chunk=10
 * 
 * @author Alex - Session 07/02/2026
 */
#[AsCommand(
    name: 'app:kizeo:download-media',
    description: 'Télécharge les photos d\'équipements depuis l\'API Kizeo (consomme kizeo_jobs type=photo)',
)]
class DownloadMediaCommand extends Command
{
    private const DEFAULT_LIMIT = 200;
    private const DEFAULT_CHUNK_SIZE = 20;
    private const MAX_ATTEMPTS = 3;
    private const API_DELAY_MS = 100_000; // 100ms entre chaque appel API
    private const MEMORY_CHECK_THRESHOLD = 200 * 1024 * 1024; // 200 MB
    private const STUCK_JOB_TIMEOUT_HOURS = 1;

    private string $storagePath;

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly KizeoJobRepository $jobRepository,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
        private readonly string $projectDir,
    ) {
        parent::__construct();
        $this->storagePath = $this->projectDir . '/storage';
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED,
                'Nombre total de jobs à traiter',
                self::DEFAULT_LIMIT)
            ->addOption('chunk', 'c', InputOption::VALUE_REQUIRED,
                'Taille des chunks (nombre de jobs traités avant flush)',
                self::DEFAULT_CHUNK_SIZE)
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED,
                'Filtrer par agence (ex: S60, S100)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Mode simulation : affiche les jobs sans télécharger')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE,
                'Relancer les jobs en échec (reset failed → pending)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $chunkSize = (int) $input->getOption('chunk');
        $agencyCode = $input->getOption('agency');
        $dryRun = $input->getOption('dry-run');
        $retryFailed = $input->getOption('retry-failed');
        $isVerbose = $output->isVerbose();

        $startTime = microtime(true);

        // Header
        $io->title('SOMAFI - Download Media (Photos Équipements Kizeo)');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));
        $io->text(sprintf('⚙️  Limit: %d | Chunk: %d | Agency: %s',
            $limit, $chunkSize, $agencyCode ?? 'TOUTES'));

        if ($dryRun) {
            $io->warning('🔍 Mode DRY-RUN activé — aucun téléchargement');
        }

        // 0. Retry failed si demandé
        if ($retryFailed) {
            $resetCount = $this->resetFailedJobs($agencyCode);
            $io->text(sprintf('🔄 %d jobs failed remis en pending', $resetCount));
        }

        // 1. Reset jobs bloqués (processing > 1h)
        $stuckCount = $this->resetStuckJobs();
        if ($stuckCount > 0) {
            $io->text(sprintf('🔧 %d jobs bloqués remis en pending', $stuckCount));
        }

        // 2. Récupérer les jobs photo pending
        $jobs = $this->getPendingPhotoJobs($limit, $agencyCode);

        if (empty($jobs)) {
            $io->success('Aucun job photo en attente.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('📷 %d jobs photo à traiter', count($jobs)));
        $io->newLine();

        // Stats
        $stats = [
            'total' => count($jobs),
            'downloaded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_bytes' => 0,
        ];

        // 3. Traiter par chunks
        $chunks = array_chunk($jobs, $chunkSize);

        foreach ($chunks as $chunkIndex => $chunk) {
            if ($isVerbose) {
                $io->text(sprintf('   📦 Chunk %d/%d (%d jobs)',
                    $chunkIndex + 1, count($chunks), count($chunk)));
            }

            // Marquer le chunk comme processing
            if (!$dryRun) {
                $this->markChunkProcessing($chunk);
            }

            // Traiter chaque job du chunk
            foreach ($chunk as $job) {
                $jobResult = $this->processJob($job, $dryRun, $isVerbose, $io);

                match ($jobResult['status']) {
                    'done' => $stats['downloaded']++,
                    'failed' => $stats['failed']++,
                    'skipped' => $stats['skipped']++,
                };

                $stats['total_bytes'] += $jobResult['file_size'] ?? 0;

                // Pause entre les appels API
                if (!$dryRun) {
                    usleep(self::API_DELAY_MS);
                }
            }

            // Flush + clear mémoire après chaque chunk
            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

            // Vérification mémoire
            $this->checkMemoryUsage($io);

            // Progress résumé
            $processed = $stats['downloaded'] + $stats['failed'] + $stats['skipped'];
            $io->text(sprintf('      → %d/%d | ✅ %d | ❌ %d | ⏭️ %d | %.1f MB',
                $processed, $stats['total'],
                $stats['downloaded'], $stats['failed'], $stats['skipped'],
                $stats['total_bytes'] / 1024 / 1024
            ));
        }

        // Résumé final
        $duration = round(microtime(true) - $startTime, 2);
        $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

        $io->newLine();
        $io->section('📊 Résumé Download Media');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Jobs traités', $stats['total']],
                ['Photos téléchargées', $stats['downloaded']],
                ['Échecs', $stats['failed']],
                ['Skippés (max attempts)', $stats['skipped']],
                ['Volume total', sprintf('%.2f MB', $stats['total_bytes'] / 1024 / 1024)],
                ['Durée', sprintf('%s sec (~%.1f min)', $duration, $duration / 60)],
                ['Mémoire pic', sprintf('%s MB', $memoryPeak)],
            ]
        );

        $this->kizeoLogger->info('=== FIN DOWNLOAD-MEDIA ===', [
            'downloaded' => $stats['downloaded'],
            'failed' => $stats['failed'],
            'skipped' => $stats['skipped'],
            'total_bytes' => $stats['total_bytes'],
            'duration_sec' => $duration,
            'memory_peak_mb' => $memoryPeak,
        ]);

        if ($stats['failed'] > 0) {
            $io->warning(sprintf('⚠️ %d job(s) en échec — relancer avec --retry-failed', $stats['failed']));
        }

        $io->success(sprintf(
            '✅ %d photos téléchargées (%.2f MB) en %s sec',
            $stats['downloaded'],
            $stats['total_bytes'] / 1024 / 1024,
            $duration
        ));

        return Command::SUCCESS;
    }

    // =========================================================================
    // TRAITEMENT D'UN JOB
    // =========================================================================

    /**
     * Traite un job photo individuel
     * 
     * @param array<string, mixed> $job Données du job depuis kizeo_jobs
     * @return array{status: string, file_size: int}
     */
    private function processJob(array $job, bool $dryRun, bool $isVerbose, SymfonyStyle $io): array
    {
        $jobId = (int) $job['id'];
        $formId = (int) $job['form_id'];
        $dataId = (int) $job['data_id'];
        $mediaName = $job['media_name'];
        $equipNumero = $job['equipment_numero'] ?? 'UNKNOWN';
        $agencyCode = $job['agency_code'];
        $idContact = (int) $job['id_contact'];
        $annee = $job['annee'];
        $visite = $job['visite'];
        $attempts = (int) $job['attempts'];

        // Vérifier max attempts
        if ($attempts >= self::MAX_ATTEMPTS) {
            if ($isVerbose) {
                $io->text(sprintf('      ⏭️ Job #%d: max attempts atteint (%d)', $jobId, $attempts));
            }
            return ['status' => 'skipped', 'file_size' => 0];
        }

        // Dry-run : afficher seulement
        if ($dryRun) {
            if ($isVerbose) {
                $io->text(sprintf('      📷 Job #%d: %s/%s → %s_%s [DRY-RUN]',
                    $jobId, $agencyCode, $idContact, $equipNumero, $this->derivePhotoType($mediaName)));
            }
            return ['status' => 'done', 'file_size' => 0];
        }

        try {
            // Incrémenter attempts
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET attempts = attempts + 1, started_at = NOW() WHERE id = :id',
                ['id' => $jobId]
            );

            // Appel API Kizeo : GET /forms/{formId}/data/{dataId}/medias/{mediaName}
            $imageContent = $this->kizeoApi->downloadMedia($formId, $dataId, $mediaName);

            if ($imageContent === null || $imageContent === '' || $imageContent === false) {
                throw new \RuntimeException('API a retourné un contenu vide');
            }

            // Construire le chemin local
            $localPath = $this->buildLocalPath($agencyCode, $idContact, $annee, $visite, $equipNumero, $mediaName, $dataId);

            // Créer le répertoire si nécessaire
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Écrire le fichier
            $bytesWritten = file_put_contents($localPath, $imageContent);

            if ($bytesWritten === false) {
                throw new \RuntimeException('Impossible d\'écrire le fichier : ' . $localPath);
            }

            // Marquer done
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET status = :status, local_path = :path, file_size = :size, completed_at = NOW() WHERE id = :id',
                [
                    'status' => 'done',
                    'path' => $localPath,
                    'size' => $bytesWritten,
                    'id' => $jobId,
                ]
            );

            // Libérer mémoire
            unset($imageContent);

            if ($isVerbose) {
                $io->text(sprintf('      ✅ #%d: %s/%d/%s/%s/%s (%.1f KB)',
                    $jobId, $agencyCode, $idContact, $annee, $visite,
                    basename($localPath),
                    $bytesWritten / 1024
                ));
            }

            $this->kizeoLogger->debug('Photo téléchargée', [
                'job_id' => $jobId,
                'form_id' => $formId,
                'data_id' => $dataId,
                'media_name' => $mediaName,
                'local_path' => $localPath,
                'file_size' => $bytesWritten,
            ]);

            return ['status' => 'done', 'file_size' => $bytesWritten];

        } catch (\Exception $e) {
            // Marquer failed
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET status = :status, last_error = :error WHERE id = :id',
                [
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'id' => $jobId,
                ]
            );

            $this->kizeoLogger->warning('Échec téléchargement photo', [
                'job_id' => $jobId,
                'form_id' => $formId,
                'data_id' => $dataId,
                'media_name' => $mediaName,
                'error' => $e->getMessage(),
                'attempts' => $attempts + 1,
            ]);

            if ($isVerbose) {
                $io->text(sprintf('      ❌ #%d: %s (attempt %d/%d)',
                    $jobId, $e->getMessage(), $attempts + 1, self::MAX_ATTEMPTS));
            }

            return ['status' => 'failed', 'file_size' => 0];
        }
    }

    // =========================================================================
    // CONSTRUCTION DU CHEMIN LOCAL
    // =========================================================================

    /**
     * Construit le chemin local pour une photo
     * 
     * Format : storage/img/{agency}/{id_contact}/{annee}/{visite}/{equipNumero}_{photoType}_{dataId}.jpg
     * 
     * Le data_id est inclus dans le nom pour garantir l'unicité :
     * un même client peut avoir plusieurs CR (data_ids) pour la même visite,
     * chacun contenant des photos du même type pour le même équipement.
     */
    private function buildLocalPath(
        string $agencyCode,
        int $idContact,
        string $annee,
        string $visite,
        string $equipNumero,
        string $mediaName,
        int $dataId
    ): string {
        $photoType = $this->derivePhotoType($mediaName);
        $extension = pathinfo($mediaName, PATHINFO_EXTENSION) ?: 'jpg';
        $extension = strtolower($extension);

        // Format : {equipNumero}_{photoType}_{dataId}.{ext}
        $filename = sprintf('%s_%s_%d.%s',
            $this->sanitizeFilename($equipNumero),
            $photoType,
            $dataId,
            $extension
        );

        return sprintf('%s/img/%s/%d/%s/%s/%s',
            $this->storagePath,
            strtoupper($agencyCode),
            $idContact,
            $annee,
            strtoupper($visite),
            $filename
        );
    }

    /**
     * Déduit le type de photo depuis le media_name Kizeo
     * 
     * Utilise la logique de ExtractedMedia::normalizePhotoType()
     */
    private function derivePhotoType(string $mediaName): string
    {
        return ExtractedMedia::normalizePhotoType($mediaName);
    }

    /**
     * Sanitize un nom pour utilisation dans un nom de fichier
     */
    private function sanitizeFilename(string $name): string
    {
        // Remplacer les caractères non alphanumériques par _
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        // Supprimer les underscores multiples
        return preg_replace('/_+/', '_', trim($sanitized, '_'));
    }

    // =========================================================================
    // REQUÊTES KIZEO_JOBS
    // =========================================================================

    /**
     * Récupère les jobs photo en attente
     * 
     * @return array<int, array<string, mixed>>
     */
    private function getPendingPhotoJobs(int $limit, ?string $agencyCode = null): array
    {
        $sql = "SELECT * FROM kizeo_jobs 
                WHERE job_type = 'photo' 
                AND status = 'pending'";
        $params = [];

        if ($agencyCode) {
            $sql .= ' AND agency_code = :agency';
            $params['agency'] = strtoupper($agencyCode);
        }

        $sql .= ' ORDER BY priority ASC, created_at ASC LIMIT :limit';
        $params['limit'] = $limit;

        return $this->connection->fetchAllAssociative($sql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);
    }

    /**
     * Marque un chunk de jobs comme 'processing'
     * 
     * @param array<int, array<string, mixed>> $chunk
     */
    private function markChunkProcessing(array $chunk): void
    {
        $ids = array_column($chunk, 'id');
        if (empty($ids)) return;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->executeStatement(
            "UPDATE kizeo_jobs SET status = 'processing', started_at = NOW() WHERE id IN ($placeholders)",
            $ids
        );
    }

    /**
     * Reset les jobs bloqués en 'processing' depuis plus d'une heure
     */
    private function resetStuckJobs(): int
    {
        return $this->connection->executeStatement(
            "UPDATE kizeo_jobs 
             SET status = 'pending', started_at = NULL 
             WHERE job_type = 'photo' 
             AND status = 'processing' 
             AND started_at < NOW() - INTERVAL :hours HOUR",
            ['hours' => self::STUCK_JOB_TIMEOUT_HOURS]
        );
    }

    /**
     * Reset les jobs failed → pending (pour retry)
     */
    private function resetFailedJobs(?string $agencyCode = null): int
    {
        $sql = "UPDATE kizeo_jobs 
                SET status = 'pending', last_error = NULL, attempts = 0
                WHERE job_type = 'photo' 
                AND status = 'failed'";
        $params = [];

        if ($agencyCode) {
            $sql .= ' AND agency_code = :agency';
            $params['agency'] = strtoupper($agencyCode);
        }

        return $this->connection->executeStatement($sql, $params);
    }

    // =========================================================================
    // GESTION MÉMOIRE
    // =========================================================================

    /**
     * Vérifie l'utilisation mémoire et déclenche un GC si nécessaire
     */
    private function checkMemoryUsage(SymfonyStyle $io): void
    {
        $currentMemory = memory_get_usage(true);

        if ($currentMemory > self::MEMORY_CHECK_THRESHOLD) {
            $this->em->clear();
            gc_collect_cycles();

            $this->kizeoLogger->info('Memory cleanup (download-media)', [
                'before_mb' => round($currentMemory / 1024 / 1024, 1),
                'after_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            ]);
        }
    }
}
