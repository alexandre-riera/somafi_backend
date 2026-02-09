<?php

namespace App\Command\Kizeo;

use App\Repository\KizeoJobRepository;
use App\Service\Kizeo\KizeoApiService;
use App\Service\Kizeo\PhotoTypeResolver;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commande de téléchargement des photos d'équipements depuis l'API Kizeo
 * 
 * Consomme les jobs de type 'photo' dans la table kizeo_jobs.
 * Même pattern que DownloadPdfCommand : chunks, gestion mémoire, retry.
 * 
 * Endpoint API : GET /forms/{formId}/data/{dataId}/medias/{mediaName}
 * Stockage     : storage/img/{agency}/{id_contact}/{annee}/{visite}/{filename}
 * 
 * Résolution du type de photo :
 *   Le media_name Kizeo est un hash chiffré (ex: c104785f...484c90c0-6b...).
 *   Le PhotoTypeResolver croise ce hash avec les colonnes photo_* de la table photos
 *   pour retrouver le vrai type (plaque, etiquette_somafi, compte_rendu, etc.).
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
 * @updated Session 08/02/2026 - Intégration PhotoTypeResolver (croisement table photos)
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
        private readonly Connection $connection,
        private readonly PhotoTypeResolver $photoTypeResolver,
        private readonly LoggerInterface $kizeoLogger,
        #[Autowire('%kernel.project_dir%')]
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

        // ━━━ FIX OOM : Désactiver le SQL logger Doctrine (DebugStack) ━━━
        // En mode dev, Doctrine stocke CHAQUE requête SQL en mémoire.
        // Avec 50 000 jobs × 3-5 requêtes = 150K+ entrées → OOM garanti.
        $this->connection->getConfiguration()->setMiddlewares([]);

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

        // 2. Compter les jobs photo pending (sans tout charger en mémoire)
        $totalJobs = $this->countPendingPhotoJobs($limit, $agencyCode);

        if ($totalJobs === 0) {
            $io->success('Aucun job photo en attente.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('📷 %d jobs photo à traiter', $totalJobs));
        $io->newLine();

        // Stats
        $stats = [
            'total' => $totalJobs,
            'downloaded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_bytes' => 0,
        ];

        // 3. Traiter par chunks — fetch progressif depuis la DB
        //    Au lieu de charger 50 000 rows d'un coup, on fetch chunk par chunk.
        //    Les jobs traités passent en done/failed, donc le LIMIT suivant
        //    retourne automatiquement les prochains pending.
        $chunkIndex = 0;
        $totalChunks = (int) ceil($totalJobs / $chunkSize);
        $dryRunOffset = 0; // En dry-run, les jobs restent 'pending' → on doit paginer avec OFFSET

        while (true) {
            // Fetch un chunk depuis la DB (les done/failed sont exclus automatiquement)
            $chunk = $this->getPendingPhotoJobs($chunkSize, $agencyCode, $dryRun ? $dryRunOffset : 0);

            if (empty($chunk)) {
                break; // Plus de jobs pending
            }

            if ($dryRun) {
                $dryRunOffset += count($chunk);
            }

            $chunkIndex++;
            if ($isVerbose) {
                $io->text(sprintf('   📦 Chunk %d/~%d (%d jobs)',
                    $chunkIndex, $totalChunks, count($chunk)));
            }

            // ━━━ Résolution batch des types photo via croisement table photos ━━━
            $photoTypes = $this->photoTypeResolver->resolveBatch($chunk);

            // Marquer le chunk comme processing
            if (!$dryRun) {
                $this->markChunkProcessing($chunk);
            }

            // Traiter chaque job du chunk
            foreach ($chunk as $job) {
                $jobId = (int) $job['id'];

                // Type résolu depuis le croisement kizeo_jobs ↔ photos
                $photoType = $photoTypes[$jobId] ?? 'autre';

                $jobResult = $this->processJob($job, $photoType, $dryRun, $isVerbose, $io);

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

            // ━━━ FIX OOM : Nettoyage mémoire après chaque chunk ━━━
            // Note: on utilise DBAL direct (pas d'entités ORM), donc flush/clear
            // de l'EntityManager est inutile ici. On force le GC à la place.
            gc_collect_cycles();

            // Libérer le cache du resolver (mémoire)
            $this->photoTypeResolver->clearCache();

            // Vérification mémoire
            $this->checkMemoryUsage($io);

            // Progress résumé
            $processed = $stats['downloaded'] + $stats['failed'] + $stats['skipped'];
            $io->text(sprintf('      → %d/%d | ✅ %d | ❌ %d | ⏭️ %d | %.1f MB',
                $processed, $stats['total'],
                $stats['downloaded'], $stats['failed'], $stats['skipped'],
                $stats['total_bytes'] / 1024 / 1024
            ));

            // ━━━ FIX OOM : Libérer le chunk traité ━━━
            unset($chunk, $photoTypes);

            // Vérifier si on a atteint la limite demandée
            if ($processed >= $limit) {
                break;
            }
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
     * @param array<string, mixed> $job       Données du job depuis kizeo_jobs
     * @param string               $photoType Type résolu via PhotoTypeResolver (ex: "plaque", "etiquette_somafi")
     * @return array{status: string, file_size: int}
     */
    private function processJob(array $job, string $photoType, bool $dryRun, bool $isVerbose, SymfonyStyle $io): array
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

        // Détecter multi-media (media_names séparés par virgule)
        $mediaNames = array_filter(array_map('trim', explode(',', $mediaName)));
        $isMultiMedia = count($mediaNames) > 1;

        // Dry-run : afficher seulement
        if ($dryRun) {
            $suffix = $isMultiMedia ? sprintf(' [MULTI:%d]', count($mediaNames)) : '';
            if ($isVerbose) {
                $io->text(sprintf('      📷 Job #%d: %s/%d → %s_%s%s [DRY-RUN]',
                    $jobId, $agencyCode, $idContact, $equipNumero, $photoType, $suffix));
            }
            return ['status' => 'done', 'file_size' => 0];
        }

        try {
            // Incrémenter attempts
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET attempts = attempts + 1, started_at = NOW() WHERE id = :id',
                ['id' => $jobId]
            );

            $totalBytes = 0;
            $lastPath = '';

            foreach ($mediaNames as $partIndex => $singleMedia) {
                // Suffixe _p1, _p2... uniquement si multi-media
                $partType = $isMultiMedia
                    ? $photoType . '_p' . ($partIndex + 1)
                    : $photoType;

                // Appel API Kizeo : GET /forms/{formId}/data/{dataId}/medias/{mediaName}
                $imageContent = $this->kizeoApi->downloadMedia($formId, $dataId, $singleMedia);

                if ($imageContent === null || $imageContent === '' || $imageContent === false) {
                    if ($isMultiMedia) {
                        // En multi-media, on log et on continue les autres parties
                        $this->kizeoLogger->warning('Multi-media: partie vide', [
                            'job_id' => $jobId,
                            'part' => $partIndex + 1,
                            'total_parts' => count($mediaNames),
                            'media_name' => $singleMedia,
                        ]);
                        continue;
                    }
                    throw new \RuntimeException('API a retourné un contenu vide');
                }

                // Construire le chemin local avec le type résolu
                $localPath = $this->buildLocalPath($agencyCode, $idContact, $annee, $visite, $equipNumero, $partType, $dataId);

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

                $totalBytes += $bytesWritten;
                $lastPath = $localPath;

                // Libérer mémoire
                unset($imageContent);

                if ($isVerbose && $isMultiMedia) {
                    $io->text(sprintf('        📎 Part %d/%d: %s (%.1f KB)',
                        $partIndex + 1, count($mediaNames), basename($localPath), $bytesWritten / 1024));
                }
            }

            // Vérifier qu'au moins une partie a été téléchargée
            if ($totalBytes === 0) {
                throw new \RuntimeException('Aucune partie téléchargée (multi-media: ' . count($mediaNames) . ' parties)');
            }

            // Marquer done
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET status = :status, local_path = :path, file_size = :size, completed_at = NOW() WHERE id = :id',
                [
                    'status' => 'done',
                    'path' => $lastPath,
                    'size' => $totalBytes,
                    'id' => $jobId,
                ]
            );

            // Mettre à jour la table photos (is_downloaded + local_path)
            $this->updatePhotoRecord($equipNumero, $idContact, $annee, $visite, $lastPath);

            if ($isVerbose) {
                $multiLabel = $isMultiMedia ? sprintf(' [%d parts]', count($mediaNames)) : '';
                $io->text(sprintf('      ✅ #%d: %s/%d/%s/%s/%s (%.1f KB)%s',
                    $jobId, $agencyCode, $idContact, $annee, $visite,
                    basename($lastPath), $totalBytes / 1024, $multiLabel));
            }

            $this->kizeoLogger->debug('Photo téléchargée', [
                'job_id' => $jobId,
                'form_id' => $formId,
                'data_id' => $dataId,
                'media_name' => $mediaName,
                'photo_type' => $photoType,
                'multi_media' => $isMultiMedia,
                'parts' => count($mediaNames),
                'local_path' => $lastPath,
                'file_size' => $totalBytes,
            ]);

            return ['status' => 'done', 'file_size' => $totalBytes];

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
                'photo_type' => $photoType,
                'multi_media' => $isMultiMedia,
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
     * 
     * Le photoType est résolu par PhotoTypeResolver via croisement avec la table photos
     * (ex: "plaque", "etiquette_somafi", "compte_rendu" au lieu de "autre").
     */
    private function buildLocalPath(
        string $agencyCode,
        int $idContact,
        string $annee,
        string $visite,
        string $equipNumero,
        string $photoType,
        int $dataId
    ): string {
        // Format : {equipNumero}_{photoType}_{dataId}.jpg
        $filename = sprintf('%s_%s_%d.jpg',
            $this->sanitizeFilename($equipNumero),
            $photoType,
            $dataId
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
    // MISE À JOUR TABLE PHOTOS
    // =========================================================================

    /**
     * Met à jour la table photos après téléchargement réussi
     * 
     * Marque is_downloaded = 1 et enregistre le local_path.
     * Ne fait rien si la ligne n'existe pas (équipement hors-contrat par ex).
     */
    private function updatePhotoRecord(
        string $equipNumero,
        int $idContact,
        string $annee,
        string $visite,
        string $localPath,
    ): void {
        try {
            $this->connection->executeStatement(
                'UPDATE photos 
                 SET is_downloaded = 1, local_path = :path, date_modification = NOW() 
                 WHERE numero_equipement = :eq 
                 AND id_contact = :ic 
                 AND annee = :an 
                 AND visite = :vi 
                 AND is_downloaded = 0',
                [
                    'path' => $localPath,
                    'eq' => $equipNumero,
                    'ic' => $idContact,
                    'an' => $annee,
                    'vi' => $visite,
                ]
            );
        } catch (\Exception $e) {
            // Non bloquant — on log mais on ne fait pas échouer le job
            $this->kizeoLogger->debug('Impossible de mettre à jour photos.is_downloaded', [
                'equipment_numero' => $equipNumero,
                'id_contact' => $idContact,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // REQUÊTES KIZEO_JOBS
    // =========================================================================

    /**
     * Compte les jobs photo en attente (sans charger les données)
     */
    private function countPendingPhotoJobs(int $maxCount, ?string $agencyCode = null): int
    {
        $sql = "SELECT COUNT(*) FROM kizeo_jobs 
                WHERE job_type = 'photo' 
                AND status = 'pending'";
        $params = [];

        if ($agencyCode) {
            $sql .= ' AND agency_code = :agency';
            $params['agency'] = strtoupper($agencyCode);
        }

        $count = (int) $this->connection->fetchOne($sql, $params);
        return min($count, $maxCount);
    }

    /**
     * Récupère les jobs photo en attente
     * 
     * @return array<int, array<string, mixed>>
     */
    private function getPendingPhotoJobs(int $limit, ?string $agencyCode = null, int $offset = 0): array
    {
        $sql = "SELECT * FROM kizeo_jobs 
                WHERE job_type = 'photo' 
                AND status = 'pending'";
        $params = [];
        $types = [];

        if ($agencyCode) {
            $sql .= ' AND agency_code = :agency';
            $params['agency'] = strtoupper($agencyCode);
        }

        $sql .= ' ORDER BY priority ASC, created_at ASC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $types['limit'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['offset'] = \Doctrine\DBAL\ParameterType::INTEGER;

        return $this->connection->fetchAllAssociative($sql, $params, $types);
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
            gc_collect_cycles();

            $afterMemory = memory_get_usage(true);
            $this->kizeoLogger->info('Memory cleanup (download-media)', [
                'before_mb' => round($currentMemory / 1024 / 1024, 1),
                'after_mb' => round($afterMemory / 1024 / 1024, 1),
            ]);

            // Si le GC n'a rien libéré et qu'on est à 80%+ de la limite, alerter
            if ($afterMemory > $currentMemory * 0.95) {
                $io->warning(sprintf('⚠️ Mémoire élevée : %d MB — le GC n\'a pas libéré significativement',
                    round($afterMemory / 1024 / 1024)));
            }
        }
    }
}