<?php

namespace App\Command\Kizeo;

use App\Service\Kizeo\KizeoApiService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marque les formulaires Kizeo comme "lus" en se basant sur les data_ids des kizeo_jobs
 * 
 * CONTEXTE : Pendant le rattrapage historique (fetch-all), les formulaires n'ont PAS 
 * été marqués comme lus (le fetch-all bypass le lu/non-lu). Avant de passer en mode 
 * CRON incrémental (fetch-forms qui ne récupère que les non-lus), il faut marquer 
 * comme lus tous les formulaires déjà traités.
 * 
 * STRATÉGIE :
 * 1. Récupérer les data_ids distincts depuis kizeo_jobs, groupés par form_id
 * 2. Pour chaque form_id, appeler POST /forms/{formId}/markasreadbyaction/read
 *    avec le body { "data_ids": [id1, id2, ...] }
 * 3. L'API Kizeo accepte les data_ids par lots
 * 
 * IMPORTANT : Exécuter cette commande AVANT PurgeJobsCommand !
 * Les data_ids sont récupérés depuis kizeo_jobs, si on purge avant, on perd les IDs.
 * 
 * Usage:
 *   php bin/console app:kizeo:mark-as-read                    # Tous les formulaires
 *   php bin/console app:kizeo:mark-as-read --agency=S100      # Une seule agence
 *   php bin/console app:kizeo:mark-as-read --dry-run          # Simulation
 *   php bin/console app:kizeo:mark-as-read --batch=100        # 100 data_ids par appel API
 * 
 * @author Alex - Session 07/02/2026
 */
#[AsCommand(
    name: 'app:kizeo:mark-as-read',
    description: 'Marque les formulaires Kizeo comme lus à partir des data_ids de kizeo_jobs',
)]
class MarkAsReadCommand extends Command
{
    /**
     * Nombre max de data_ids par appel API markAsRead
     * L'API Kizeo semble accepter des lots, mais on limite pour éviter les timeouts
     */
    private const DEFAULT_BATCH_SIZE = 50;
    
    /**
     * Pause entre chaque appel API (ms)
     */
    private const API_DELAY_MS = 200_000; // 200ms

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED,
                'Filtrer par agence (ex: S60, S100)')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED,
                'Nombre de data_ids par appel API',
                self::DEFAULT_BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Mode simulation : affiche sans appeler l\'API Kizeo')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agencyCode = $input->getOption('agency');
        $batchSize = (int) $input->getOption('batch');
        $dryRun = $input->getOption('dry-run');

        $startTime = microtime(true);

        // Header
        $io->title('SOMAFI - Mark As Read (Formulaires Kizeo)');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));
        $io->text(sprintf('⚙️  Batch: %d | Agency: %s', $batchSize, $agencyCode ?? 'TOUTES'));

        if ($dryRun) {
            $io->warning('🔍 Mode DRY-RUN — aucun appel API');
        }

        // 1. Récupérer les data_ids groupés par form_id
        $formDataIds = $this->getDistinctDataIdsByFormId($agencyCode);

        if (empty($formDataIds)) {
            $io->success('Aucun formulaire à marquer comme lu.');
            return Command::SUCCESS;
        }

        // Afficher le résumé
        $totalDataIds = 0;
        foreach ($formDataIds as $formId => $dataIds) {
            $count = count($dataIds);
            $totalDataIds += $count;
            $io->text(sprintf('   📋 Form %d : %d data_ids à marquer', $formId, $count));
        }
        $io->text(sprintf('   📊 Total : %d data_ids sur %d formulaires', 
            $totalDataIds, count($formDataIds)));
        $io->newLine();

        // Confirmation si pas dry-run
        if (!$dryRun) {
            if (!$io->confirm(sprintf(
                'Marquer %d data_ids comme lus sur %d formulaires Kizeo ?',
                $totalDataIds, count($formDataIds)
            ), true)) {
                $io->warning('Opération annulée.');
                return Command::SUCCESS;
            }
        }

        // 2. Traiter par form_id
        $stats = [
            'forms_processed' => 0,
            'data_ids_marked' => 0,
            'api_calls' => 0,
            'errors' => 0,
        ];

        foreach ($formDataIds as $formId => $dataIds) {
            $io->text(sprintf('🔄 Form %d — %d data_ids...', $formId, count($dataIds)));

            // Découper en batches
            $batches = array_chunk($dataIds, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                if ($dryRun) {
                    $io->text(sprintf('   [DRY-RUN] Batch %d/%d : %d data_ids (ex: %s)',
                        $batchIndex + 1, count($batches), count($batch),
                        implode(', ', array_slice($batch, 0, 5))
                    ));
                    $stats['data_ids_marked'] += count($batch);
                    continue;
                }

                try {
                    // Appel API : POST /forms/{formId}/markasreadbyaction/read
                    $this->kizeoApi->markMultipleAsRead($formId, $batch);

                    $stats['data_ids_marked'] += count($batch);
                    $stats['api_calls']++;

                    $io->text(sprintf('   ✅ Batch %d/%d : %d data_ids marqués',
                        $batchIndex + 1, count($batches), count($batch)));

                    $this->kizeoLogger->info('Batch markAsRead', [
                        'form_id' => $formId,
                        'count' => count($batch),
                        'batch_index' => $batchIndex + 1,
                    ]);

                    // Pause entre les appels API
                    usleep(self::API_DELAY_MS);

                } catch (\Exception $e) {
                    $stats['errors']++;
                    $io->text(sprintf('   ❌ Batch %d/%d : %s',
                        $batchIndex + 1, count($batches), $e->getMessage()));

                    $this->kizeoLogger->error('Échec markAsRead batch', [
                        'form_id' => $formId,
                        'batch_index' => $batchIndex + 1,
                        'count' => count($batch),
                        'error' => $e->getMessage(),
                    ]);

                    // On continue avec les autres batches
                }
            }

            $stats['forms_processed']++;
        }

        // Résumé final
        $duration = round(microtime(true) - $startTime, 2);

        $io->newLine();
        $io->section('📊 Résumé Mark As Read');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Formulaires traités', $stats['forms_processed']],
                ['Data IDs marqués comme lus', $stats['data_ids_marked']],
                ['Appels API Kizeo', $stats['api_calls']],
                ['Erreurs', $stats['errors']],
                ['Durée', sprintf('%s sec', $duration)],
            ]
        );

        if ($stats['errors'] > 0) {
            $io->warning(sprintf('⚠️ %d erreur(s) rencontrée(s)', $stats['errors']));
        }

        $io->success(sprintf(
            '✅ %d data_ids marqués comme lus sur %d formulaires',
            $stats['data_ids_marked'],
            $stats['forms_processed']
        ));

        if (!$dryRun) {
            $io->text('💡 Tu peux maintenant vérifier avec :');
            $io->text('   php bin/console app:kizeo:fetch-forms --dry-run');
            $io->text('   → Devrait retourner 0 formulaire non lu (si aucun nouveau CR depuis)');
        }

        return Command::SUCCESS;
    }

    // =========================================================================
    // REQUÊTES SQL
    // =========================================================================

    /**
     * Récupère les data_ids distincts groupés par form_id depuis kizeo_jobs
     * 
     * @return array<int, array<int>> [form_id => [data_id1, data_id2, ...]]
     */
    private function getDistinctDataIdsByFormId(?string $agencyCode = null): array
    {
        $sql = "SELECT DISTINCT form_id, data_id 
                FROM kizeo_jobs";
        $params = [];

        if ($agencyCode) {
            $sql .= ' WHERE agency_code = :agency';
            $params['agency'] = strtoupper($agencyCode);
        }

        $sql .= ' ORDER BY form_id ASC, data_id ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        // Grouper par form_id
        $result = [];
        foreach ($rows as $row) {
            $formId = (int) $row['form_id'];
            $dataId = (int) $row['data_id'];
            
            if (!isset($result[$formId])) {
                $result[$formId] = [];
            }
            $result[$formId][] = $dataId;
        }

        return $result;
    }
}
