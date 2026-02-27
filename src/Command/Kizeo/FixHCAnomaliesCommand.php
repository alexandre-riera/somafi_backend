<?php

namespace App\Command\Kizeo;

use App\Repository\AgencyRepository;
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
 * Commande de correction ciblée : anomalies manquantes sur les équipements HC
 * 
 * Contexte : le champ zone_de_texte2 (textarea anomalie HC) n'était pas lu
 * par extractAnomaliesHC() → les HC avec statut B/C/D/E avaient anomalies = NULL.
 * 
 * Principe :
 *   1. SELECT les HC impactés (anomalies IS NULL + statut B/C/D/E)
 *   2. Grouper par kizeo_form_id + kizeo_data_id (1 appel API par CR)
 *   3. Fetch le JSON Kizeo, extraire tableau2[kizeo_index].zone_de_texte2.value
 *   4. UPDATE ciblé du champ anomalies
 * 
 * Usage:
 *   php bin/console app:kizeo:fix-hc-anomalies                  # Toutes les agences
 *   php bin/console app:kizeo:fix-hc-anomalies --agency=S120    # Une seule agence
 *   php bin/console app:kizeo:fix-hc-anomalies --dry-run        # Simulation
 *   php bin/console app:kizeo:fix-hc-anomalies --include-a      # Inclure aussi les statuts A
 * 
 * Créée le 27/02/2026 - Fix zone_de_texte2 non mappé pour les HC
 */
#[AsCommand(
    name: 'app:kizeo:fix-hc-anomalies',
    description: 'Corrige les anomalies manquantes des équipements HC en re-fetchant zone_de_texte2 depuis Kizeo',
)]
class FixHCAnomaliesCommand extends Command
{
    private const API_DELAY_MS = 200_000; // 200ms entre chaque appel API

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly AgencyRepository $agencyRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED,
                'Traiter une seule agence (code: S10, S40, S120, etc.)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Mode simulation : affiche ce qui serait fait sans modifier la BDD')
            ->addOption('include-a', null, InputOption::VALUE_NONE,
                'Inclure aussi les équipements HC en statut A (bon état) - par défaut seuls B/C/D/E sont traités')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $agencyFilter = $input->getOption('agency');
        $includeA = $input->getOption('include-a');

        $io->title('Fix anomalies HC - zone_de_texte2');

        if ($dryRun) {
            $io->warning('MODE DRY-RUN : aucune modification ne sera faite en BDD');
        }

        $startTime = microtime(true);
        $totalStats = [
            'agencies' => 0,
            'hc_found' => 0,
            'api_calls' => 0,
            'updated' => 0,
            'already_ok' => 0,
            'no_anomaly_in_kizeo' => 0,
            'api_errors' => 0,
        ];

        // Récupérer les agences
        $agencies = $this->agencyRepository->findWithKizeoForm();

        foreach ($agencies as $agency) {
            $code = $agency->getCode();

            if ($agencyFilter && strtoupper($agencyFilter) !== strtoupper($code)) {
                continue;
            }

            $io->section("Agence {$code}");
            $stats = $this->processAgency($code, $dryRun, $includeA, $io);

            $totalStats['agencies']++;
            $totalStats['hc_found'] += $stats['hc_found'];
            $totalStats['api_calls'] += $stats['api_calls'];
            $totalStats['updated'] += $stats['updated'];
            $totalStats['already_ok'] += $stats['already_ok'];
            $totalStats['no_anomaly_in_kizeo'] += $stats['no_anomaly_in_kizeo'];
            $totalStats['api_errors'] += $stats['api_errors'];
        }

        // Bilan final
        $elapsed = round(microtime(true) - $startTime, 1);

        $io->newLine();
        $io->success(sprintf(
            "Terminé en %ss | %d agences | %d HC trouvés | %d appels API | %d mis à jour | %d déjà OK | %d sans anomalie Kizeo | %d erreurs API",
            $elapsed,
            $totalStats['agencies'],
            $totalStats['hc_found'],
            $totalStats['api_calls'],
            $totalStats['updated'],
            $totalStats['already_ok'],
            $totalStats['no_anomaly_in_kizeo'],
            $totalStats['api_errors']
        ));

        return Command::SUCCESS;
    }

    private function processAgency(string $agencyCode, bool $dryRun, bool $includeA, SymfonyStyle $io): array
    {
        $stats = [
            'hc_found' => 0,
            'api_calls' => 0,
            'updated' => 0,
            'already_ok' => 0,
            'no_anomaly_in_kizeo' => 0,
            'api_errors' => 0,
        ];

        $tableName = 'equipement_' . strtolower($agencyCode);

        // 1. Récupérer les HC avec anomalies manquantes
        $statuts = $includeA ? "'A','B','C','D','E'" : "'B','C','D','E'";
        
        $sql = "SELECT id, numero_equipement, statut_equipement, kizeo_form_id, kizeo_data_id, kizeo_index
                FROM {$tableName}
                WHERE is_hors_contrat = 1
                  AND (anomalies IS NULL OR anomalies = '')
                  AND kizeo_form_id IS NOT NULL
                  AND kizeo_data_id IS NOT NULL
                  AND kizeo_index IS NOT NULL
                  AND statut_equipement IN ({$statuts})
                ORDER BY kizeo_form_id, kizeo_data_id, kizeo_index";

        $rows = $this->connection->fetchAllAssociative($sql);
        $stats['hc_found'] = count($rows);

        if (empty($rows)) {
            $io->text('  ✅ Aucun HC avec anomalies manquantes');
            return $stats;
        }

        $io->text(sprintf('  🔍 %d HC avec anomalies manquantes', count($rows)));

        // 2. Grouper par form_id + data_id pour minimiser les appels API
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['kizeo_form_id'] . '_' . $row['kizeo_data_id'];
            $grouped[$key][] = $row;
        }

        $io->text(sprintf('  📡 %d CR Kizeo à re-fetcher', count($grouped)));

        // 3. Pour chaque CR unique, fetcher et patcher
        foreach ($grouped as $key => $equipments) {
            $formId = (int) $equipments[0]['kizeo_form_id'];
            $dataId = (int) $equipments[0]['kizeo_data_id'];

            // Appel API Kizeo
            try {
                $formData = $this->kizeoApi->getFormData($formId, $dataId);
                $stats['api_calls']++;
            } catch (\Exception $e) {
                $stats['api_errors']++;
                $io->text(sprintf(
                    '    ❌ Erreur API form=%d data=%d : %s',
                    $formId, $dataId, $e->getMessage()
                ));
                $this->kizeoLogger->error('Fix HC anomalies - Erreur API', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($formData === null) {
                $stats['api_errors']++;
                $io->text(sprintf('    ⚠️ CR introuvable form=%d data=%d', $formId, $dataId));
                continue;
            }

            // Extraire tableau2 (équipements HC)
            $fields = $formData['fields'] ?? [];
            $hcEquipments = $fields['tableau2']['value'] ?? [];

            // Patcher chaque HC de ce CR
            foreach ($equipments as $row) {
                $index = (int) $row['kizeo_index'];
                $equipId = $row['id'];
                $numEquip = $row['numero_equipement'];

                if (!isset($hcEquipments[$index])) {
                    $io->text(sprintf(
                        '    ⚠️ %s (id=%d) - index %d introuvable dans tableau2 (%d items)',
                        $numEquip, $equipId, $index, count($hcEquipments)
                    ));
                    $stats['api_errors']++;
                    continue;
                }

                $equipData = $hcEquipments[$index];

                // Extraire zone_de_texte2
                $anomalie = $this->extractZoneDeTexte2($equipData);

                if ($anomalie === null) {
                    $io->text(sprintf(
                        '    ℹ️ %s (id=%d) - zone_de_texte2 vide dans Kizeo (statut %s)',
                        $numEquip, $equipId, $row['statut_equipement']
                    ));
                    $stats['no_anomaly_in_kizeo']++;
                    continue;
                }

                // UPDATE en BDD
                if (!$dryRun) {
                    $this->connection->update(
                        $tableName,
                        ['anomalies' => $anomalie],
                        ['id' => $equipId]
                    );
                }

                $stats['updated']++;
                $io->text(sprintf(
                    '    %s %s (id=%d) → "%s"',
                    $dryRun ? '🔵 [DRY]' : '✅',
                    $numEquip, $equipId,
                    mb_strlen($anomalie) > 80 ? mb_substr($anomalie, 0, 80) . '...' : $anomalie
                ));

                $this->kizeoLogger->info('Fix HC anomalie', [
                    'agency' => $agencyCode,
                    'equipment_id' => $equipId,
                    'numero' => $numEquip,
                    'anomalie' => $anomalie,
                    'dry_run' => $dryRun,
                ]);
            }

            // Pause entre les appels API
            usleep(self::API_DELAY_MS);
        }

        return $stats;
    }

    /**
     * Extrait la valeur de zone_de_texte2 depuis les données brutes d'un équipement HC
     */
    private function extractZoneDeTexte2(array $equipData): ?string
    {
        if (!isset($equipData['zone_de_texte2'])) {
            return null;
        }

        $fieldData = $equipData['zone_de_texte2'];

        if (!is_array($fieldData) || !isset($fieldData['value'])) {
            return null;
        }

        $value = $fieldData['value'];

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
