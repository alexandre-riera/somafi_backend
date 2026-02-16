<?php

namespace App\Command\Kizeo;

use App\Entity\KizeoJob;
use App\Repository\KizeoJobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:kizeo:purge-jobs',
    description: 'Purge les jobs Kizeo terminés (done) depuis plus de N jours',
)]
class PurgeJobsCommand extends Command
{
    public function __construct(
        private readonly KizeoJobRepository $jobRepository,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Supprimer les jobs done > N jours', 14)
            ->addOption('include-failed', null, InputOption::VALUE_NONE, 'Inclure aussi les jobs failed (max attempts atteint)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation sans suppression')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');
        $includeFailed = $input->getOption('include-failed');
        $dryRun = $input->getOption('dry-run');

        $io->title('KIZEO JOBS PURGE');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));

        if ($dryRun) {
            $io->warning('MODE DRY-RUN — Aucune suppression ne sera effectuée');
        }

        $threshold = new \DateTime("-{$days} days");
        $io->text(sprintf('🗑️  Seuil : jobs terminés avant le %s (%d jours)', $threshold->format('d/m/Y H:i'), $days));
        $io->newLine();

        $totalPurged = 0;

        // ── 1. Purge des jobs DONE ──────────────────────────────
        $doneCount = $this->countJobsToPurge(KizeoJob::STATUS_DONE, $days);
        $io->section(sprintf('Jobs DONE à purger : %s', number_format($doneCount)));

        if ($doneCount > 0) {
            if ($dryRun) {
                $this->displayBreakdown($io, KizeoJob::STATUS_DONE, $days);
            } else {
                $purged = $this->jobRepository->purgeDoneJobs($days);
                $totalPurged += $purged;
                $io->text(sprintf('  ✅ %s jobs done supprimés', number_format($purged)));
                $this->kizeoLogger->info('[PurgeJobs] Purged {count} done jobs older than {days} days', [
                    'count' => $purged,
                    'days' => $days,
                ]);
            }
        } else {
            $io->text('  Rien à purger.');
        }

        // ── 2. Purge des jobs FAILED (optionnel) ────────────────
        if ($includeFailed) {
            $io->newLine();
            $failedCount = $this->countJobsToPurge(KizeoJob::STATUS_FAILED, $days);
            $io->section(sprintf('Jobs FAILED à purger : %s', number_format($failedCount)));

            if ($failedCount > 0) {
                if ($dryRun) {
                    $this->displayBreakdown($io, KizeoJob::STATUS_FAILED, $days);
                } else {
                    $purged = $this->purgeFailedJobs($days);
                    $totalPurged += $purged;
                    $io->text(sprintf('  ✅ %s jobs failed supprimés', number_format($purged)));
                    $this->kizeoLogger->info('[PurgeJobs] Purged {count} failed jobs older than {days} days', [
                        'count' => $purged,
                        'days' => $days,
                    ]);
                }
            } else {
                $io->text('  Rien à purger.');
            }
        }

        // ── 3. Résumé ───────────────────────────────────────────
        $io->newLine();

        if ($dryRun) {
            $previewTotal = $doneCount + ($includeFailed ? $this->countJobsToPurge(KizeoJob::STATUS_FAILED, $days) : 0);
            $io->success(sprintf('[DRY-RUN] %s jobs auraient été supprimés.', number_format($previewTotal)));
        } else {
            $io->success(sprintf('%s jobs purgés au total.', number_format($totalPurged)));
        }

        // Afficher les stats restantes
        $this->displayRemainingStats($io);

        return Command::SUCCESS;
    }

    /**
     * Compte les jobs à purger pour un status donné
     */
    private function countJobsToPurge(string $status, int $days): int
    {
        $threshold = new \DateTime("-{$days} days");
        $dateColumn = ($status === KizeoJob::STATUS_DONE) ? 'j.completedAt' : 'j.createdAt';

        return (int) $this->jobRepository->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :status')
            ->andWhere("{$dateColumn} < :threshold")
            ->setParameter('status', $status)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Affiche la répartition par type pour le dry-run
     */
    private function displayBreakdown(SymfonyStyle $io, string $status, int $days): void
    {
        $threshold = new \DateTime("-{$days} days");
        $dateColumn = ($status === KizeoJob::STATUS_DONE) ? 'j.completedAt' : 'j.createdAt';

        $results = $this->jobRepository->createQueryBuilder('j')
            ->select('j.jobType, COUNT(j.id) as nb')
            ->where('j.status = :status')
            ->andWhere("{$dateColumn} < :threshold")
            ->setParameter('status', $status)
            ->setParameter('threshold', $threshold)
            ->groupBy('j.jobType')
            ->getQuery()
            ->getResult();

        foreach ($results as $row) {
            $io->text(sprintf('  📋 %s : %s jobs', strtoupper($row['jobType']), number_format($row['nb'])));
        }
    }

    /**
     * Purge les jobs failed (même pattern que purgeDoneJobs mais sur status failed)
     */
    private function purgeFailedJobs(int $days): int
    {
        $threshold = new \DateTime("-{$days} days");

        return $this->jobRepository->createQueryBuilder('j')
            ->delete()
            ->where('j.status = :failed')
            ->andWhere('j.createdAt < :threshold')
            ->setParameter('failed', KizeoJob::STATUS_FAILED)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Affiche les stats de la table après purge
     */
    private function displayRemainingStats(SymfonyStyle $io): void
    {
        $io->section('État de la table kizeo_jobs');

        foreach ([KizeoJob::TYPE_PDF, KizeoJob::TYPE_PHOTO] as $type) {
            $stats = $this->jobRepository->getStatsByType($type);
            $total = array_sum($stats);

            $io->text(sprintf('  %s %s (%s total)',
                $type === KizeoJob::TYPE_PDF ? '📄' : '📷',
                strtoupper($type),
                number_format($total)
            ));
            $io->text(sprintf('    🕐 Pending: %s | ⚙️ Processing: %s | ✅ Done: %s | ❌ Failed: %s',
                number_format($stats['pending']),
                number_format($stats['processing']),
                number_format($stats['done']),
                number_format($stats['failed'])
            ));
        }
    }
}
