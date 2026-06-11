<?php

declare(strict_types=1);

namespace App\Command\Kizeo;

use App\Service\Kizeo\KizeoListBuilder;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dé-corruption des repère_site_client pollués (NULL-only).
 *
 * Vide les repères qui sont en réalité des dimensions (numérique > 600),
 * APRÈS sauvegarde des valeurs écrasées dans une table de backup par agence
 * (repere_backup_sXX). Les repères 1-600 (vrais repères clients) et non
 * numériques sont laissés intacts.
 *
 * On NE recouvre PAS la valeur d'origine : elle a été écrasée par une cote et
 * est irrécupérable (cf. analyse 11/06 : hauteur/largeur sont saines dans 96 %
 * des cas, le repère ne matche aucune dimension actuelle dans 60 %). On vide,
 * point. Aucun décalage de colonnes.
 *
 * SÉCURITÉ :
 * - DRY-RUN par défaut. Aucune écriture sans --apply.
 * - Avec --apply : transaction par agence, backup AVANT UPDATE, confirmation.
 * - Idempotent : un repère mis à NULL ne re-matche plus le critère.
 *
 * Cette commande ne touche QUE la BDD. Le rebuild + PUT vers Kizeo se fait
 * ensuite via app:kizeo:sync-equipment-list (cron de PUT gelé pendant l'opé).
 *
 * Usage :
 *   php bin/console app:kizeo:fix-corrupted-repere                 # dry-run, 13 agences
 *   php bin/console app:kizeo:fix-corrupted-repere --agency=S170   # dry-run, 1 agence
 *   php bin/console app:kizeo:fix-corrupted-repere --apply         # exécution réelle
 */
#[AsCommand(
    name: 'app:kizeo:fix-corrupted-repere',
    description: 'Dé-corruption : vide (NULL) les repère_site_client > 600 (dimensions), après backup. Dry-run par défaut.',
)]
class FixCorruptedRepereCommand extends Command
{
    /** Borne haute d'un repère client plausible. Au-delà = dimension à vider. */
    private const REPERE_MAX_LEGIT = 600;

    /** Taille des lots pour les UPDATE par liste d'id. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly KizeoListBuilder $listBuilder,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence à cibler (ex: S170). Sinon, les 13 agences.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Exécute réellement (backup + UPDATE). Sans ce flag : dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $targetAgency = $input->getOption('agency');

        if ($targetAgency !== null) {
            $targetAgency = strtoupper((string) $targetAgency);
            if (!$this->listBuilder->isValidAgencyCode($targetAgency)) {
                $io->error("Code agence invalide : {$targetAgency}");
                return Command::FAILURE;
            }
            $agencies = [$targetAgency];
        } else {
            $agencies = $this->listBuilder->getAgencyCodes();
        }

        $io->title('Dé-corruption repère_site_client (NULL-only)');
        if ($apply) {
            $io->warning('MODE --apply : backup puis mise à NULL réelle des repères > 600.');
        } else {
            $io->writeln('<comment>DRY-RUN : aucune écriture. Lance avec --apply pour exécuter.</comment>');
        }

        // Aperçu global avant toute écriture
        $plan = [];
        $grandTotal = 0;
        foreach ($agencies as $agency) {
            $candidates = $this->findCandidates($agency);
            $plan[$agency] = $candidates;
            $grandTotal += count($candidates);
        }

        $io->section('Repères à vider par agence');
        $rows = [];
        foreach ($plan as $agency => $candidates) {
            if (count($candidates) > 0) {
                $rows[] = [$agency, (string) count($candidates)];
            }
        }
        $rows[] = ['<info>TOTAL</info>', '<info>' . $grandTotal . '</info>'];
        $io->table(['Agence', 'Repères > 600 à NULL'], $rows);

        if ($grandTotal === 0) {
            $io->success('Aucun repère à dé-corrompre.');
            return Command::SUCCESS;
        }

        // Échantillon (5 premières lignes, toutes agences confondues)
        $this->displaySample($io, $plan);

        if (!$apply) {
            $io->note(sprintf(
                '[DRY-RUN] %d repère(s) seraient sauvegardés puis mis à NULL. Relance avec --apply pour exécuter.',
                $grandTotal
            ));
            return Command::SUCCESS;
        }

        // ── Exécution réelle ──
        if (!$io->confirm(sprintf('Confirmer la mise à NULL de %d repère(s) (backup d\'abord) ?', $grandTotal), false)) {
            $io->warning('Annulé. Rien n\'a été modifié.');
            return Command::SUCCESS;
        }

        $totalBackedUp = 0;
        $totalNulled = 0;
        $failed = 0;

        foreach ($plan as $agency => $candidates) {
            if (count($candidates) === 0) {
                continue;
            }

            try {
                $result = $this->applyAgency($agency, $candidates);
                $totalBackedUp += $result['backed_up'];
                $totalNulled += $result['nulled'];
                $io->text(sprintf(
                    '[%s] backup : %d, NULL : %d (table %s)',
                    $agency, $result['backed_up'], $result['nulled'], $result['backup_table']
                ));
            } catch (\Throwable $e) {
                $failed++;
                $io->error(sprintf('[%s] ÉCHEC (transaction annulée) : %s', $agency, $e->getMessage()));
                $this->kizeoLogger->error('Échec dé-corruption repère', [
                    'agency' => $agency,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->section('Résumé');
        $io->table(['Métrique', 'Valeur'], [
            ['Repères sauvegardés', (string) $totalBackedUp],
            ['Repères mis à NULL', (string) $totalNulled],
            ['Agences en erreur', (string) $failed],
        ]);

        if ($failed > 0) {
            $io->error('Terminé avec des erreurs (voir ci-dessus).');
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d repère(s) vidés. Prochaine étape : rebuild + PUT via app:kizeo:sync-equipment-list (cron gelé).',
            $totalNulled
        ));

        return Command::SUCCESS;
    }

    /**
     * Récupère les lignes actives dont le repère est une dimension (> 600).
     *
     * Filtrage numérique fait en PHP avec la MÊME règle que la détection
     * (is_numeric && > 600), pour que les comptes correspondent exactement.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findCandidates(string $agency): array
    {
        $table = 'equipement_' . strtolower($agency);

        $sql = <<<SQL
            SELECT id, id_contact, numero_equipement, visite, repere_site_client, hauteur, largeur
            FROM {$table}
            WHERE is_archive = 0
              AND repere_site_client IS NOT NULL
              AND TRIM(repere_site_client) <> ''
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql);
        } catch (\Throwable $e) {
            $this->kizeoLogger->error('Erreur lecture candidats dé-corruption', [
                'agency' => $agency,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return array_values(array_filter($rows, function (array $row): bool {
            $repere = trim((string) ($row['repere_site_client'] ?? ''));
            return is_numeric($repere) && (float) $repere > self::REPERE_MAX_LEGIT;
        }));
    }

    /**
     * Applique la dé-corruption pour une agence dans une transaction :
     * crée la table de backup, sauvegarde, puis met à NULL.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array{backed_up: int, nulled: int, backup_table: string}
     */
    private function applyAgency(string $agency, array $candidates): array
    {
        $suffix = strtolower($agency);
        $equipTable = 'equipement_' . $suffix;
        $backupTable = 'repere_backup_' . $suffix;

        $this->ensureBackupTable($backupTable);

        $ids = array_map(static fn(array $r) => (int) $r['id'], $candidates);

        $this->connection->beginTransaction();
        try {
            // 1. Backup des valeurs écrasées
            $backedUp = 0;
            foreach ($candidates as $row) {
                $this->connection->insert($backupTable, [
                    'equipement_id' => (int) $row['id'],
                    'id_contact' => (string) ($row['id_contact'] ?? ''),
                    'numero_equipement' => (string) ($row['numero_equipement'] ?? ''),
                    'visite' => (string) ($row['visite'] ?? ''),
                    'repere_ecrase' => (string) ($row['repere_site_client'] ?? ''),
                    'hauteur' => (string) ($row['hauteur'] ?? ''),
                    'largeur' => (string) ($row['largeur'] ?? ''),
                    'date_sauvegarde' => date('Y-m-d H:i:s'),
                ]);
                $backedUp++;
            }

            // 2. UPDATE … SET repere = NULL WHERE id IN (…), par lots
            $nulled = 0;
            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $nulled += (int) $this->connection->executeStatement(
                    "UPDATE {$equipTable} SET repere_site_client = NULL WHERE id IN ({$placeholders})",
                    $chunk
                );
            }

            $this->connection->commit();

            $this->kizeoLogger->info('Dé-corruption repère appliquée', [
                'agency' => $agency,
                'backed_up' => $backedUp,
                'nulled' => $nulled,
            ]);

            return ['backed_up' => $backedUp, 'nulled' => $nulled, 'backup_table' => $backupTable];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Crée la table de backup si elle n'existe pas.
     */
    private function ensureBackupTable(string $backupTable): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS {$backupTable} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                equipement_id INT NOT NULL,
                id_contact VARCHAR(50) DEFAULT NULL,
                numero_equipement VARCHAR(100) DEFAULT NULL,
                visite VARCHAR(20) DEFAULT NULL,
                repere_ecrase VARCHAR(255) DEFAULT NULL,
                hauteur VARCHAR(20) DEFAULT NULL,
                largeur VARCHAR(20) DEFAULT NULL,
                date_sauvegarde DATETIME NOT NULL,
                INDEX idx_equipement_id (equipement_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

        $this->connection->executeStatement($sql);
    }

    /**
     * Affiche un échantillon des repères qui seront vidés.
     *
     * @param array<string, array<int, array<string, mixed>>> $plan
     */
    private function displaySample(SymfonyStyle $io, array $plan): void
    {
        $sample = [];
        foreach ($plan as $agency => $candidates) {
            foreach ($candidates as $row) {
                $sample[] = [
                    $agency,
                    (string) ($row['id_contact'] ?? ''),
                    (string) ($row['numero_equipement'] ?? ''),
                    (string) ($row['visite'] ?? ''),
                    (string) ($row['repere_site_client'] ?? ''),
                    (string) ($row['hauteur'] ?? ''),
                    (string) ($row['largeur'] ?? ''),
                ];
                if (count($sample) >= 5) {
                    break 2;
                }
            }
        }

        if (!empty($sample)) {
            $io->text('Échantillon (5 premiers) :');
            $io->table(
                ['agence', 'id_contact', 'équipement', 'visite', 'repère → NULL', 'hauteur', 'largeur'],
                $sample
            );
        }
    }
}
