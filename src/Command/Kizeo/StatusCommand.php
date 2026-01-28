<?php

namespace App\Command\Kizeo;

use App\Entity\KizeoJob;
use App\Repository\KizeoJobRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de monitoring des jobs Kizeo
 * 
 * Affiche l'état de la file d'attente des téléchargements PDF et photos
 * 
 * Usage:
 *   php bin/console app:kizeo:status           # Vue globale
 *   php bin/console app:kizeo:status --agency=S60  # Filtrer par agence
 *   php bin/console app:kizeo:status --json    # Output JSON (pour scripts)
 */
#[AsCommand(
    name: 'app:kizeo:status',
    description: 'Affiche le statut des jobs de téléchargement Kizeo (PDF et photos)',
)]
class StatusCommand extends Command
{
    public function __construct(
        private readonly KizeoJobRepository $jobRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'agency',
                'a',
                InputOption::VALUE_REQUIRED,
                'Filtrer par code agence (S10, S60, etc.)'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Sortie au format JSON (pour intégration scripts)'
            )
            ->addOption(
                'failed',
                'f',
                InputOption::VALUE_NONE,
                'Afficher uniquement les jobs en échec'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Nombre de jobs en échec à afficher',
                10
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agencyCode = $input->getOption('agency');
        $jsonOutput = $input->getOption('json');
        $failedOnly = $input->getOption('failed');
        $limit = (int) $input->getOption('limit');

        // Collecter les données
        $data = $this->collectStats($agencyCode);
        $failedJobs = $this->jobRepository->findRecentFailedJobs($limit);

        // Mode JSON
        if ($jsonOutput) {
            $output->writeln(json_encode([
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
                'agency_filter' => $agencyCode,
                'pdf' => $data['pdf'],
                'photo' => $data['photo'],
                'totals' => $data['totals'],
                'failed_jobs' => array_map(fn($job) => [
                    'id' => $job->getId(),
                    'type' => $job->getJobType(),
                    'agency' => $job->getAgencyCode(),
                    'data_id' => $job->getDataId(),
                    'media' => $job->getMediaName(),
                    'error' => $job->getLastError(),
                    'attempts' => $job->getAttempts(),
                ], $failedJobs),
            ], JSON_PRETTY_PRINT));
            
            return Command::SUCCESS;
        }

        // Mode affichage console
        if ($failedOnly) {
            $this->displayFailedJobs($io, $failedJobs);
            return Command::SUCCESS;
        }

        $this->displayDashboard($io, $data, $agencyCode);
        $this->displayFailedJobs($io, $failedJobs);

        return Command::SUCCESS;
    }

    /**
     * Collecte les statistiques des jobs
     * 
     * @return array{pdf: array, photo: array, totals: array}
     */
    private function collectStats(?string $agencyCode): array
    {
        if ($agencyCode) {
            // Stats pour une agence spécifique
            $pdfStats = $this->jobRepository->getStatsByTypeAndAgency(KizeoJob::TYPE_PDF, $agencyCode);
            $photoStats = $this->jobRepository->getStatsByTypeAndAgency(KizeoJob::TYPE_PHOTO, $agencyCode);
        } else {
            // Stats globales
            $pdfStats = $this->jobRepository->getStatsByType(KizeoJob::TYPE_PDF);
            $photoStats = $this->jobRepository->getStatsByType(KizeoJob::TYPE_PHOTO);
        }

        return [
            'pdf' => $pdfStats,
            'photo' => $photoStats,
            'totals' => [
                'pending' => $pdfStats['pending'] + $photoStats['pending'],
                'processing' => $pdfStats['processing'] + $photoStats['processing'],
                'done' => $pdfStats['done'] + $photoStats['done'],
                'failed' => $pdfStats['failed'] + $photoStats['failed'],
            ],
        ];
    }

    /**
     * Affiche le dashboard principal
     */
    private function displayDashboard(SymfonyStyle $io, array $data, ?string $agencyCode): void
    {
        $title = $agencyCode 
            ? sprintf('KIZEO JOBS STATUS - Agence %s', $agencyCode)
            : 'KIZEO JOBS STATUS - Global';

        $io->title($title);
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));
        $io->newLine();

        // Section PDF
        $io->section('📄 PDF Techniciens');
        $this->displayStatsTable($io, $data['pdf']);

        // Section Photos
        $io->section('📷 Photos Équipements');
        $this->displayStatsTable($io, $data['photo']);

        // Résumé global
        $io->section('📊 Totaux');
        $this->displayStatsTable($io, $data['totals']);

        // Alertes si nécessaire
        $this->displayAlerts($io, $data);
    }

    /**
     * Affiche un tableau de statistiques
     */
    private function displayStatsTable(SymfonyStyle $io, array $stats): void
    {
        $io->table(
            ['Status', 'Count'],
            [
                ['🕐 Pending', $this->formatNumber($stats['pending'])],
                ['⚙️ Processing', $this->formatNumber($stats['processing'])],
                ['✅ Done', $this->formatNumber($stats['done'])],
                ['❌ Failed', $this->formatNumber($stats['failed'])],
            ]
        );
    }

    /**
     * Affiche les jobs en échec
     * 
     * @param KizeoJob[] $failedJobs
     */
    private function displayFailedJobs(SymfonyStyle $io, array $failedJobs): void
    {
        if (empty($failedJobs)) {
            $io->success('Aucun job en échec ! 🎉');
            return;
        }

        $io->section(sprintf('❌ Jobs en échec (%d)', count($failedJobs)));

        $rows = [];
        foreach ($failedJobs as $job) {
            $identifier = $job->getJobType() === KizeoJob::TYPE_PDF 
                ? sprintf('PDF #%d', $job->getDataId())
                : sprintf('%s', $job->getMediaName() ?? 'N/A');

            $rows[] = [
                $job->getId(),
                strtoupper($job->getJobType()),
                $job->getAgencyCode(),
                $identifier,
                $job->getAttempts() . '/' . $job->getMaxAttempts(),
                $this->truncateError($job->getLastError()),
            ];
        }

        $io->table(
            ['ID', 'Type', 'Agency', 'Identifier', 'Attempts', 'Last Error'],
            $rows
        );

        $io->note('Utilisez --limit=N pour voir plus de jobs en échec');
    }

    /**
     * Affiche des alertes si nécessaire
     */
    private function displayAlerts(SymfonyStyle $io, array $data): void
    {
        $alerts = [];

        // Beaucoup de jobs pending
        if ($data['totals']['pending'] > 500) {
            $alerts[] = sprintf(
                '⚠️ %d jobs en attente - vérifier que les CRON tournent',
                $data['totals']['pending']
            );
        }

        // Jobs en processing depuis longtemps (possible blocage)
        if ($data['totals']['processing'] > 50) {
            $alerts[] = sprintf(
                '⚠️ %d jobs en processing - possible blocage',
                $data['totals']['processing']
            );
        }

        // Taux d'échec élevé
        $totalProcessed = $data['totals']['done'] + $data['totals']['failed'];
        if ($totalProcessed > 0) {
            $failureRate = ($data['totals']['failed'] / $totalProcessed) * 100;
            if ($failureRate > 5) {
                $alerts[] = sprintf(
                    '⚠️ Taux d\'échec élevé: %.1f%% (%d/%d)',
                    $failureRate,
                    $data['totals']['failed'],
                    $totalProcessed
                );
            }
        }

        if (!empty($alerts)) {
            $io->section('⚠️ Alertes');
            foreach ($alerts as $alert) {
                $io->warning($alert);
            }
        }
    }

    /**
     * Formate un nombre avec séparateur de milliers
     */
    private function formatNumber(int $number): string
    {
        return number_format($number, 0, ',', ' ');
    }

    /**
     * Tronque un message d'erreur pour l'affichage
     */
    private function truncateError(?string $error, int $maxLength = 50): string
    {
        if ($error === null) {
            return '-';
        }

        if (strlen($error) <= $maxLength) {
            return $error;
        }

        return substr($error, 0, $maxLength - 3) . '...';
    }
}
