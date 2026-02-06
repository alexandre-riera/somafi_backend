<?php

namespace App\Command\Kizeo;

use App\Entity\KizeoJob;
use App\Repository\KizeoJobRepository;
use App\Service\Kizeo\KizeoPdfDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de téléchargement des PDF techniciens depuis l'API Kizeo
 * 
 * Traite les jobs de type 'pdf' en status 'pending' dans la table kizeo_jobs.
 * Télécharge le PDF via l'API Kizeo et le sauvegarde localement.
 * 
 * Usage:
 *   php bin/console app:kizeo:download-pdf                          # 30 jobs, chunks de 5
 *   php bin/console app:kizeo:download-pdf --limit=100 --chunk=10   # 100 jobs, chunks de 10
 *   php bin/console app:kizeo:download-pdf --agency=S40 -v          # Agence S40 uniquement, verbose
 *   php bin/console app:kizeo:download-pdf --dry-run                # Simulation
 * 
 * Stratégie mémoire :
 *   - Traitement par chunks (défaut: 5)
 *   - flush + clear de Doctrine entre chaque chunk
 *   - unset du contenu binaire PDF après sauvegarde
 *   - Seuil mémoire à 200 MB → GC forcé
 *   - Pause de 500ms entre chaque appel API (PDF = gros fichiers)
 */
#[AsCommand(
    name: 'app:kizeo:download-pdf',
    description: 'Télécharge les PDF techniciens depuis l\'API Kizeo (traite les jobs PDF pending)',
)]
class DownloadPdfCommand extends Command
{
    private const DEFAULT_LIMIT = 30;
    private const DEFAULT_CHUNK_SIZE = 5;
    private const API_DELAY_MS = 500_000; // 500ms entre chaque appel API (PDF plus lourd)
    private const MEMORY_CHECK_THRESHOLD = 200 * 1024 * 1024; // 200 MB

    public function __construct(
        private readonly KizeoPdfDownloader $pdfDownloader,
        private readonly KizeoJobRepository $jobRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED,
                'Nombre total de jobs à traiter',
                self::DEFAULT_LIMIT)
            ->addOption('chunk', 'c', InputOption::VALUE_REQUIRED,
                'Taille des chunks (flush Doctrine entre chaque)',
                self::DEFAULT_CHUNK_SIZE)
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED,
                'Filtrer par agence (code: S10, S40, S60, etc.)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Mode simulation : affiche ce qui serait fait sans télécharger')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $chunkSize = (int) $input->getOption('chunk');
        $agencyFilter = $input->getOption('agency');
        $dryRun = $input->getOption('dry-run');
        $isVerbose = $output->isVerbose();

        $startTime = microtime(true);

        // =============================================
        // En-tête
        // =============================================
        $io->title('SOMAFI - Téléchargement PDF Techniciens Kizeo');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));
        $io->text(sprintf('⚙️  Limit: %d | Chunk: %d | Agence: %s',
            $limit, $chunkSize, $agencyFilter ?? 'toutes'));

        if ($dryRun) {
            $io->warning('🔍 Mode DRY-RUN activé — aucun téléchargement ne sera effectué');
        }

        $this->kizeoLogger->info('=== DÉBUT DOWNLOAD-PDF ===', [
            'limit' => $limit,
            'chunk_size' => $chunkSize,
            'agency_filter' => $agencyFilter,
            'dry_run' => $dryRun,
        ]);

        // =============================================
        // Étape 1 : Reset des jobs bloqués
        // =============================================
        $resetCount = $this->jobRepository->resetStuckJobs(60);
        if ($resetCount > 0) {
            $io->text(sprintf('🔄 %d job(s) bloqué(s) remis en pending', $resetCount));
            $this->kizeoLogger->info('Jobs bloqués reset (download-pdf)', ['count' => $resetCount]);
        }

        // =============================================
        // Étape 2 : Récupérer les jobs PDF pending
        // =============================================
        $jobs = $this->fetchPendingJobs($limit, $agencyFilter);

        if (empty($jobs)) {
            $io->success('✅ Aucun job PDF en attente — rien à faire !');
            $this->kizeoLogger->info('Aucun job PDF pending (download-pdf)');
            return Command::SUCCESS;
        }

        $io->text(sprintf('📄 %d job(s) PDF à traiter', count($jobs)));
        $io->newLine();

        // =============================================
        // Étape 3 : Traiter par chunks
        // =============================================
        $chunks = array_chunk($jobs, $chunkSize);
        $stats = [
            'total' => count($jobs),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_size' => 0,
        ];

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNum = $chunkIndex + 1;

            if ($isVerbose) {
                $io->text(sprintf('   📦 Chunk %d/%d (%d jobs)', $chunkNum, count($chunks), count($chunk)));
            }

            // Marquer le chunk comme "processing"
            if (!$dryRun) {
                foreach ($chunk as $job) {
                    $job->markAsProcessing();
                }
                $this->em->flush();
            }

            // Traiter chaque job du chunk
            foreach ($chunk as $job) {
                $result = $this->processJob($job, $dryRun, $isVerbose, $io);

                match ($result) {
                    'success' => $stats['success']++,
                    'failed' => $stats['failed']++,
                    'skipped' => $stats['skipped']++,
                };

                if ($result === 'success' && $job->getFileSize()) {
                    $stats['total_size'] += $job->getFileSize();
                }

                // Pause entre les appels API
                if (!$dryRun) {
                    usleep(self::API_DELAY_MS);
                }
            }

            // Flush + clear Doctrine après chaque chunk
            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

            // Vérification mémoire
            $this->checkMemoryUsage($io);

            // Progress
            $processed = min(($chunkIndex + 1) * $chunkSize, $stats['total']);
            $io->text(sprintf(
                '   → %d/%d traités | ✅ %d | ❌ %d | ⏭️ %d',
                $processed, $stats['total'],
                $stats['success'], $stats['failed'], $stats['skipped']
            ));
        }

        // =============================================
        // Résumé final
        // =============================================
        $duration = round(microtime(true) - $startTime, 2);
        $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $totalSizeMb = round($stats['total_size'] / 1024 / 1024, 2);

        $io->newLine();
        $io->section('📊 Résumé Download PDF');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Jobs traités', $stats['total']],
                ['Téléchargés avec succès', $stats['success']],
                ['Échoués', $stats['failed']],
                ['Skippés (max attempts)', $stats['skipped']],
                ['Volume téléchargé', sprintf('%.2f MB', $totalSizeMb)],
                ['Durée', sprintf('%s sec (~%s min)', $duration, round($duration / 60, 1))],
                ['Mémoire pic', sprintf('%s MB', $memoryPeak)],
            ]
        );

        $this->kizeoLogger->info('=== FIN DOWNLOAD-PDF ===', [
            'total' => $stats['total'],
            'success' => $stats['success'],
            'failed' => $stats['failed'],
            'skipped' => $stats['skipped'],
            'total_size_bytes' => $stats['total_size'],
            'duration_sec' => $duration,
            'memory_peak_mb' => $memoryPeak,
        ]);

        if ($stats['failed'] > 0) {
            $io->warning(sprintf('⚠️ %d job(s) en échec — ils seront retentés au prochain CRON (si attempts < max)', $stats['failed']));
        }

        $io->success(sprintf(
            '✅ %d/%d PDF téléchargés (%.2f MB) en %s sec',
            $stats['success'], $stats['total'], $totalSizeMb, $duration
        ));

        return $stats['failed'] > 0 && $stats['success'] === 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * Récupère les jobs PDF pending, avec filtre optionnel par agence
     * 
     * @return KizeoJob[]
     */
    private function fetchPendingJobs(int $limit, ?string $agencyCode): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('j')
            ->from(KizeoJob::class, 'j')
            ->where('j.status = :status')
            ->andWhere('j.jobType = :type')
            ->setParameter('status', KizeoJob::STATUS_PENDING)
            ->setParameter('type', KizeoJob::TYPE_PDF)
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($agencyCode) {
            $qb->andWhere('j.agencyCode = :agency')
               ->setParameter('agency', $agencyCode);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Traite UN job PDF
     * 
     * @return string 'success' | 'failed' | 'skipped'
     */
    private function processJob(
        KizeoJob $job,
        bool $dryRun,
        bool $isVerbose,
        SymfonyStyle $io
    ): string {
        $jobInfo = sprintf(
            'Job #%d [%s] form=%d data=%d client="%s"',
            $job->getId(),
            $job->getAgencyCode(),
            $job->getFormId(),
            $job->getDataId(),
            $job->getClientName() ?? 'N/A'
        );

        // Vérifier les tentatives max
        if (!$job->canRetry()) {
            if ($isVerbose) {
                $io->text(sprintf('      ⏭️ %s — max attempts atteint (%d/%d)',
                    $jobInfo, $job->getAttempts(), KizeoJob::MAX_ATTEMPTS));
            }

            $job->markAsFailed('Max attempts reached');

            $this->kizeoLogger->warning('Job PDF skippé (max attempts)', [
                'job_id' => $job->getId(),
                'attempts' => $job->getAttempts(),
            ]);

            return 'skipped';
        }

        // Mode dry-run
        if ($dryRun) {
            $io->text(sprintf('      🔍 [DRY-RUN] %s', $jobInfo));
            return 'success';
        }

        try {
            // Date de visite : champ dédié si disponible, sinon fallback sur created_at
            $dateVisite = $job->getDateVisite() ?? $job->getCreatedAt()->format('Y-m-d');

            // Télécharger le PDF via le service
            $localPath = $this->pdfDownloader->download(
                $job->getFormId(),
                $job->getDataId(),
                $job->getAgencyCode(),
                $job->getIdContact(),
                $job->getClientName() ?? 'INCONNU',
                $job->getAnnee(),
                $job->getVisite(),
                $dateVisite
            );

            if ($localPath !== null) {
                // Succès
                $fileSize = file_exists($localPath) ? filesize($localPath) : 0;
                $job->markAsDone($localPath, $fileSize);

                if ($isVerbose) {
                    $io->text(sprintf('      ✅ %s → %s (%s KB)',
                        $jobInfo,
                        basename($localPath),
                        round($fileSize / 1024, 1)
                    ));
                }

                $this->kizeoLogger->info('PDF téléchargé avec succès', [
                    'job_id' => $job->getId(),
                    'path' => $localPath,
                    'size' => $fileSize,
                ]);

                return 'success';
            }

            // Échec retourné par le downloader (null = erreur API ou écriture)
            $job->markAsFailed('KizeoPdfDownloader returned null');

            if ($isVerbose) {
                $io->text(sprintf('      ❌ %s — échec téléchargement (attempt %d/%d)',
                    $jobInfo, $job->getAttempts(), KizeoJob::MAX_ATTEMPTS));
            }

            $this->kizeoLogger->error('Échec téléchargement PDF', [
                'job_id' => $job->getId(),
                'form_id' => $job->getFormId(),
                'data_id' => $job->getDataId(),
                'attempt' => $job->getAttempts(),
            ]);

            return 'failed';

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());

            if ($isVerbose) {
                $io->text(sprintf('      ❌ %s — Exception: %s', $jobInfo, $e->getMessage()));
            }

            $this->kizeoLogger->error('Exception téléchargement PDF', [
                'job_id' => $job->getId(),
                'form_id' => $job->getFormId(),
                'data_id' => $job->getDataId(),
                'error' => $e->getMessage(),
                'attempt' => $job->getAttempts(),
            ]);

            return 'failed';
        }
    }

    /**
     * Vérifie l'utilisation mémoire et déclenche un GC si nécessaire
     */
    private function checkMemoryUsage(SymfonyStyle $io): void
    {
        $currentMemory = memory_get_usage(true);

        if ($currentMemory > self::MEMORY_CHECK_THRESHOLD) {
            $beforeMb = round($currentMemory / 1024 / 1024, 1);

            $this->em->clear();
            gc_collect_cycles();

            $afterMb = round(memory_get_usage(true) / 1024 / 1024, 1);

            $this->kizeoLogger->info('Memory cleanup (download-pdf)', [
                'before_mb' => $beforeMb,
                'after_mb' => $afterMb,
            ]);
        }
    }
}
