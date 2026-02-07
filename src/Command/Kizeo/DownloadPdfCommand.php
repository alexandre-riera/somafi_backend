<?php

namespace App\Command\Kizeo;

use App\Entity\KizeoJob;
use App\Repository\KizeoJobRepository;
use App\Service\Kizeo\KizeoPdfDownloader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Télécharge les PDF techniciens depuis l'API Kizeo (traite les jobs PDF pending).
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
 *   - Re-fetch des jobs à chaque chunk (fix détachement entités)
 *   - Seuil mémoire à 200 MB → GC forcé
 *   - Pause de 500ms entre chaque appel API
 * 
 * FIX 06/02/2026 : Les jobs étaient re-traités en boucle car $em->clear()
 *   détachait les entités non encore traitées. Maintenant on re-fetch à chaque
 *   chunk avec findPendingByType() — les jobs passés en 'done' ou 'failed'
 *   ne remontent plus.
 * 
 * FIX 07/02/2026 :
 *   - #1 getDateVisite() retourne string|null, pas DateTime → supprimé ->format()
 *   - #2 canRetry() vérifié AVANT markAsProcessing() (sinon -1 tentative)
 *   - #3 ManagerRegistry pour recovery EntityManager après exception Doctrine
 *   - #4 Guard PDF vide (0 bytes) → markAsFailed + unlink
 */
#[AsCommand(
    name: 'app:kizeo:download-pdf',
    description: 'Télécharge les PDF techniciens depuis l\'API Kizeo (traite les jobs PDF pending)',
)]
class DownloadPdfCommand extends Command
{
    private const DEFAULT_LIMIT = 30;
    private const DEFAULT_CHUNK_SIZE = 5;
    private const API_DELAY_MS = 500_000; // 500ms entre chaque appel API
    private const MEMORY_CHECK_THRESHOLD = 200 * 1024 * 1024; // 200 MB

    public function __construct(
        private readonly KizeoPdfDownloader $pdfDownloader,
        private readonly KizeoJobRepository $jobRepository,
        private EntityManagerInterface $em,              // FIX #3 : Plus readonly (réassigné après reset)
        private readonly ManagerRegistry $doctrine,      // FIX #3 : Recovery EM
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
            $limit, $chunkSize, $agencyFilter ?? 'TOUTES'));

        if ($dryRun) {
            $io->warning('🔍 MODE DRY-RUN — Aucun téléchargement ne sera effectué');
        }

        $this->kizeoLogger->info('=== DÉBUT DOWNLOAD-PDF ===', [
            'limit' => $limit,
            'chunk_size' => $chunkSize,
            'agency' => $agencyFilter,
            'dry_run' => $dryRun,
        ]);

        // =============================================
        // 1. Reset des jobs bloqués (> 1h en processing)
        // =============================================
        $resetCount = $this->jobRepository->resetStuckJobs(60);
        if ($resetCount > 0) {
            $io->note(sprintf('♻️  %d job(s) bloqué(s) remis en pending', $resetCount));
            $this->kizeoLogger->warning('Jobs bloqués resetés', ['count' => $resetCount]);
        }

        // =============================================
        // 2. Traitement chunk par chunk
        //    FIX 06/02 : On re-fetch à chaque itération au lieu de tout charger d'un coup.
        //    Après flush + clear, les entités sont détachées.
        //    Les jobs déjà traités (done/failed) ne remontent plus.
        // =============================================
        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_size' => 0,
        ];

        $totalProcessed = 0;
        $chunkIndex = 0;

        while ($totalProcessed < $limit) {
            // Combien de jobs on veut encore ?
            $remaining = $limit - $totalProcessed;
            $fetchSize = min($chunkSize, $remaining);

            // Re-fetch à chaque itération : les jobs done/failed ne remontent plus
            $jobs = $this->fetchPendingJobs($fetchSize, $agencyFilter);

            if (empty($jobs)) {
                if ($totalProcessed === 0) {
                    $io->success('✅ Aucun job PDF en attente — rien à faire');
                } else {
                    $io->text('   → Plus de jobs pending, arrêt anticipé.');
                }
                break;
            }

            $chunkIndex++;
            $io->section(sprintf('📦 Chunk #%d (%d jobs)', $chunkIndex, count($jobs)));

            // =============================================
            // FIX #2 : Filtrer les jobs qui ont épuisé leurs tentatives AVANT markAsProcessing()
            //   Sinon markAsProcessing() incrémente attempts, et canRetry() retourne false
            //   immédiatement → on perd 1 tentative sur MAX_ATTEMPTS.
            // =============================================
            $validJobs = [];
            foreach ($jobs as $job) {
                if (!$job->canRetry()) {
                    // Skip direct sans incrémenter attempts
                    $job->markAsFailed('Max attempts reached');
                    $stats['total']++;
                    $stats['skipped']++;
                    $totalProcessed++;

                    if ($isVerbose) {
                        $io->text(sprintf('      ⏭️ Job #%d — max attempts atteint (%d/%d)',
                            $job->getId(), $job->getAttempts(), KizeoJob::MAX_ATTEMPTS));
                    }

                    $this->kizeoLogger->warning('Job PDF skippé (max attempts)', [
                        'job_id' => $job->getId(),
                        'attempts' => $job->getAttempts(),
                    ]);

                    continue;
                }
                $validJobs[] = $job;
            }

            // Marquer les jobs valides comme "processing" (incrémente attempts)
            foreach ($validJobs as $job) {
                $job->markAsProcessing();
            }

            // FIX #3 : Flush protégé avec recovery EM
            if (!$this->safeFlush($io)) {
                $io->error('❌ Impossible de flush le marquage processing — arrêt');
                break;
            }

            // Traiter chaque job valide du chunk
            foreach ($validJobs as $job) {
                $result = $this->processJob($job, $dryRun, $isVerbose, $io);

                $stats['total']++;
                $totalProcessed++;

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
                $this->safeFlush($io);
                $this->em->clear();
                // Les entités sont détachées, mais au prochain tour de boucle
                // on re-fetch des entités fraîches via fetchPendingJobs()
            }

            // Vérification mémoire
            $this->checkMemoryUsage($io);

            $io->text(sprintf(
                '   → %d/%d traités | ✅ %d | ❌ %d | ⏭️ %d',
                $totalProcessed, $limit,
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
            $io->warning(sprintf(
                '⚠️ %d job(s) en échec — ils seront retentés au prochain CRON (si attempts < max)',
                $stats['failed']
            ));
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
     * Récupère un chunk de jobs PDF pending.
     * 
     * IMPORTANT : Cette méthode est appelée À CHAQUE CHUNK, pas une seule fois.
     * Après flush + clear, les entités précédentes sont détachées et les jobs 
     * passés en done/failed ne sont plus retournés par le WHERE status = 'pending'.
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
     * Note : canRetry() est vérifié EN AMONT dans execute(), avant markAsProcessing().
     * Ici le job est déjà marqué processing avec attempts incrémenté.
     * 
     * @return string 'success' | 'failed'
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

        // Mode dry-run
        if ($dryRun) {
            $io->text(sprintf('      🔍 [DRY-RUN] %s', $jobInfo));
            return 'success';
        }

        try {
            // =============================================
            // FIX #1 : getDateVisite() retourne string|null, pas DateTime
            //   Avant : $job->getDateVisite()->format('Y-m-d') → Fatal Error
            //   Après : utilisation directe du string, fallback sur createdAt
            // =============================================
            $dateVisite = $job->getDateVisite()
                ?? $job->getCreatedAt()->format('Y-m-d');

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
                $fileSize = file_exists($localPath) ? filesize($localPath) : 0;

                // =============================================
                // FIX #4 : Guard PDF vide (0 bytes)
                //   L'API peut retourner 200 avec un corps vide.
                //   On supprime le fichier fantôme et on marque en failed.
                // =============================================
                if ($fileSize === 0) {
                    @unlink($localPath);
                    $job->markAsFailed('PDF vide (0 bytes)');

                    $this->kizeoLogger->error('PDF téléchargé vide', [
                        'job_id' => $job->getId(),
                        'form_id' => $job->getFormId(),
                        'data_id' => $job->getDataId(),
                        'path' => $localPath,
                    ]);

                    return 'failed';
                }

                $job->markAsDone($localPath, $fileSize);

                if ($isVerbose) {
                    $io->text(sprintf('      ✅ %s → %s (%.1f KB)',
                        $jobInfo, basename($localPath), $fileSize / 1024));
                }

                $this->kizeoLogger->info('PDF téléchargé', [
                    'job_id' => $job->getId(),
                    'path' => $localPath,
                    'size' => $fileSize,
                ]);

                return 'success';
            }

            // Null retourné = échec silencieux du service
            $job->markAsFailed('KizeoPdfDownloader retourné null');

            $this->kizeoLogger->error('PDF download retourné null', [
                'job_id' => $job->getId(),
                'form_id' => $job->getFormId(),
                'data_id' => $job->getDataId(),
            ]);

            return 'failed';

        } catch (\Throwable $e) {
            $errorMsg = sprintf('%s: %s', get_class($e), $e->getMessage());

            // FIX #3 : Tenter de marquer le job en failed même si l'EM est fermé
            try {
                $job->markAsFailed($errorMsg);
            } catch (\Throwable) {
                // L'EM est fermé, on tente un reset pour persister l'erreur
                if ($this->resetEntityManagerIfNeeded($io)) {
                    $this->kizeoLogger->warning('EM reset après exception dans processJob', [
                        'job_id' => $job->getId(),
                    ]);
                }
            }

            if ($isVerbose) {
                $io->text(sprintf('      ❌ %s — %s', $jobInfo, $e->getMessage()));
            }

            $this->kizeoLogger->error('Erreur download PDF', [
                'job_id' => $job->getId(),
                'error' => $errorMsg,
                'attempt' => $job->getAttempts(),
            ]);

            return 'failed';
        }
    }

    /**
     * FIX #3 : Flush protégé avec recovery EntityManager
     * 
     * Si le flush échoue et ferme l'EM (ex: connexion MySQL perdue),
     * on reset l'EM via ManagerRegistry pour que les chunks suivants
     * puissent continuer.
     * 
     * @return bool true si le flush a réussi (ou si l'EM a été reset avec succès)
     */
    private function safeFlush(SymfonyStyle $io): bool
    {
        try {
            $this->em->flush();
            return true;
        } catch (\Throwable $e) {
            $this->kizeoLogger->error('Erreur flush Doctrine', [
                'error' => $e->getMessage(),
            ]);

            $io->text(sprintf('   ⚠️ Erreur flush : %s', $e->getMessage()));

            return $this->resetEntityManagerIfNeeded($io);
        }
    }

    /**
     * FIX #3 : Reset l'EntityManager s'il est fermé
     * 
     * Après une exception Doctrine (deadlock, connexion perdue, etc.),
     * l'EM se ferme et refuse toute opération. Le reset via ManagerRegistry
     * crée un nouvel EM fonctionnel.
     * 
     * @return bool true si l'EM a été reset avec succès
     */
    private function resetEntityManagerIfNeeded(SymfonyStyle $io): bool
    {
        if ($this->em->isOpen()) {
            return true;
        }

        try {
            $this->em = $this->doctrine->resetManager();
            $this->kizeoLogger->warning('EntityManager reset après fermeture');
            $io->text('   ♻️ EntityManager reset — reprise du traitement');
            return true;
        } catch (\Throwable $e) {
            $this->kizeoLogger->critical('Impossible de reset EntityManager', [
                'error' => $e->getMessage(),
            ]);
            $io->error('❌ Impossible de reset EntityManager : ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vérifie l'utilisation mémoire et force le GC si nécessaire
     */
    private function checkMemoryUsage(SymfonyStyle $io): void
    {
        $memoryUsage = memory_get_usage(true);

        if ($memoryUsage > self::MEMORY_CHECK_THRESHOLD) {
            gc_collect_cycles();
            $afterGc = memory_get_usage(true);

            $io->text(sprintf(
                '   🧹 GC forcé : %.1f MB → %.1f MB',
                $memoryUsage / 1024 / 1024,
                $afterGc / 1024 / 1024
            ));

            $this->kizeoLogger->info('GC forcé (seuil mémoire)', [
                'before_mb' => round($memoryUsage / 1024 / 1024, 1),
                'after_mb' => round($afterGc / 1024 / 1024, 1),
            ]);
        }
    }
}