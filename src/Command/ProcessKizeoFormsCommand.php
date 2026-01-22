<?php

namespace App\Command;

use App\Service\Kizeo\KizeoFormProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande principale de traitement des formulaires Kizeo
 * 
 * Appelée par le CRON toutes les 2 heures
 * 
 * Usage:
 *   php bin/console app:process-kizeo-forms           # Toutes les agences
 *   php bin/console app:process-kizeo-forms S100      # Agence spécifique
 *   php bin/console app:process-kizeo-forms --dry-run # Mode simulation
 */
#[AsCommand(
    name: 'app:process-kizeo-forms',
    description: 'Traite les formulaires Kizeo non lus et enregistre les équipements en BDD',
)]
class ProcessKizeoFormsCommand extends Command
{
    public function __construct(
        private readonly KizeoFormProcessor $formProcessor,
        private readonly LoggerInterface $cronLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'agencyCode',
                InputArgument::OPTIONAL,
                'Code agence à traiter (S10, S40, etc.). Si non spécifié, traite toutes les agences.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Mode simulation : affiche ce qui serait fait sans l\'exécuter'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Nombre maximum de formulaires à traiter par agence',
                50
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agencyCode = $input->getArgument('agencyCode');
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $startTime = microtime(true);

        $io->title('SOMAFI - Traitement des formulaires Kizeo');

        if ($dryRun) {
            $io->warning('Mode simulation activé - Aucune modification ne sera effectuée');
        }

        $this->cronLogger->info('Démarrage traitement Kizeo', [
            'agency' => $agencyCode ?? 'ALL',
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        try {
            if ($agencyCode) {
                // Traiter une seule agence
                $result = $this->formProcessor->processAgency($agencyCode, $limit, $dryRun);
                $this->displayResult($io, $agencyCode, $result);
            } else {
                // Traiter toutes les agences
                $results = $this->formProcessor->processAllAgencies($limit, $dryRun);
                
                foreach ($results as $code => $result) {
                    $this->displayResult($io, $code, $result);
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            $this->cronLogger->info('Traitement terminé', [
                'duration' => $duration,
            ]);

            $io->success(sprintf('Traitement terminé en %s secondes', $duration));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->cronLogger->error('Erreur traitement Kizeo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Erreur: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Affiche le résultat du traitement d'une agence
     * 
     * @param array<string, mixed> $result
     */
    private function displayResult(SymfonyStyle $io, string $agencyCode, array $result): void
    {
        if (!$result['success']) {
            $io->error(sprintf('[%s] Erreur: %s', $agencyCode, $result['error'] ?? 'Erreur inconnue'));
            return;
        }

        $io->section(sprintf('Agence %s', $agencyCode));

        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Formulaires traités', $result['forms_processed'] ?? 0],
                ['Équipements contrat créés', $result['contract_created'] ?? 0],
                ['Équipements contrat MAJ', $result['contract_updated'] ?? 0],
                ['Équipements HC créés', $result['offcontract_created'] ?? 0],
                ['Équipements HC ignorés (doublons)', $result['offcontract_skipped'] ?? 0],
                ['Photos enregistrées', $result['photos_saved'] ?? 0],
                ['Erreurs', $result['errors'] ?? 0],
            ]
        );
    }
}
