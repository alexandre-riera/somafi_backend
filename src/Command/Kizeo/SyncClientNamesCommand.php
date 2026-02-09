<?php

declare(strict_types=1);

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
 * Synchronise les raison_sociale des clients depuis Kizeo vers la BDD
 * 
 * Les noms de clients sont maintenus à jour sur Kizeo par les agences.
 * Cette commande récupère ces noms et met à jour la BDD (contact_sXX)
 * pour que le sync équipements (BDD → Kizeo) utilise les bons noms.
 * 
 * Flux : Kizeo liste clients → BDD contact_sXX.raison_sociale
 * 
 * Format liste clients Kizeo (5-6 segments pipe-séparés) :
 *   RAISON_SOCIALE:RAISON_SOCIALE|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE
 * 
 * Usage :
 *   php bin/console app:kizeo:sync-client-names                    # Toutes les agences
 *   php bin/console app:kizeo:sync-client-names --agency=S170      # Une seule agence
 *   php bin/console app:kizeo:sync-client-names --dry-run          # Simulation
 * 
 * CRON recommandé : avant le sync équipements
 *  30 minutes toute les 2 heures php bin/console app:kizeo:sync-client-names >> var/log/sync-clients.log 2>&1
 *  A heure pile 1 heure sur 2 php bin/console app:kizeo:sync-equipment-list >> var/log/sync-list.log 2>&1
 * 
 * Créé le 09/02/2026 — Phase C : complément sync noms clients
 */
#[AsCommand(
    name: 'app:kizeo:sync-client-names',
    description: 'Synchronise les raison_sociale des clients Kizeo → BDD (contact_sXX)',
)]
class SyncClientNamesCommand extends Command
{
    /** Pause entre chaque agence (éviter le rate limiting) */
    private const AGENCY_DELAY_MS = 500_000; // 500ms

    private SymfonyStyle $io;

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
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence à cibler (ex: S170)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : affiche les changements sans modifier la BDD')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $targetAgency = $input->getOption('agency');

        $this->io->title('Synchronisation Noms Clients Kizeo → BDD');

        if ($dryRun) {
            $this->io->warning('MODE DRY-RUN : aucune modification en BDD');
        }

        // Valider l'agence si spécifiée
        if ($targetAgency !== null) {
            $targetAgency = strtoupper($targetAgency);
        }

        // Récupérer les agences à traiter
        $agencies = $this->getAgenciesToProcess($targetAgency);

        if (empty($agencies)) {
            $this->io->warning('Aucune agence à traiter (vérifier kizeo_list_clients_id en BDD)');
            return Command::SUCCESS;
        }

        $this->io->info(sprintf('Agences à traiter : %d', count($agencies)));

        // Stats globales
        $globalStats = [
            'agencies_processed' => 0,
            'agencies_failed' => 0,
            'total_updated' => 0,
            'total_unchanged' => 0,
            'total_not_found' => 0,
        ];

        foreach ($agencies as $agency) {
            $code = $agency->getCode();
            $listId = $agency->getKizeoListClientsId();

            if ($listId === null || $listId === 0) {
                $this->io->note(sprintf('[%s] Pas de kizeo_list_clients_id → skip', $code));
                continue;
            }

            $this->io->section(sprintf('Agence %s — %s (listId=%d)', $code, $agency->getNom(), $listId));

            try {
                $stats = $this->syncAgency($code, $listId, $dryRun);
                $globalStats['agencies_processed']++;
                $globalStats['total_updated'] += $stats['updated'];
                $globalStats['total_unchanged'] += $stats['unchanged'];
                $globalStats['total_not_found'] += $stats['not_found'];
            } catch (\Throwable $e) {
                $globalStats['agencies_failed']++;
                $this->io->error(sprintf('[%s] ERREUR : %s', $code, $e->getMessage()));
                $this->kizeoLogger->error('Erreur sync client names', [
                    'agency' => $code,
                    'error' => $e->getMessage(),
                ]);
            }

            // Pause entre les agences
            if ($targetAgency === null) {
                usleep(self::AGENCY_DELAY_MS);
            }
        }

        // Résumé global
        $this->displayGlobalSummary($globalStats, $dryRun);

        return $globalStats['agencies_failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────
    //  Logique de synchronisation par agence
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array{updated: int, unchanged: int, not_found: int}
     */
    private function syncAgency(string $agencyCode, int $listId, bool $dryRun): array
    {
        $stats = ['updated' => 0, 'unchanged' => 0, 'not_found' => 0];

        // ── Étape 1 : GET liste clients Kizeo ──
        $this->io->text('→ GET liste clients Kizeo...');
        $kizeoResponse = $this->kizeoApi->getList($listId);

        if ($kizeoResponse === null) {
            throw new \RuntimeException(sprintf('Impossible de récupérer la liste Kizeo (listId=%d)', $listId));
        }

        $items = $kizeoResponse['list']['items'] ?? [];
        $this->io->text(sprintf('  Clients sur Kizeo : %d', count($items)));

        if (empty($items)) {
            return $stats;
        }

        // ── Étape 2 : Parser les items Kizeo ──
        $kizeoClients = $this->parseClientItems($items);
        $this->io->text(sprintf('  Clients parsés avec id_contact : %d', count($kizeoClients)));

        // ── Étape 3 : Comparer avec la BDD et mettre à jour ──
        $this->io->text('→ Comparaison avec la BDD...');
        $tableSuffix = strtolower($agencyCode);
        $contactTable = "contact_{$tableSuffix}";

        $changes = [];

        foreach ($kizeoClients as $client) {
            $idContact = $client['id_contact'];
            $kizeoName = $client['raison_sociale'];

            if (empty($idContact) || empty($kizeoName)) {
                continue;
            }

            // Récupérer le nom actuel en BDD
            $sql = "SELECT raison_sociale FROM {$contactTable} WHERE id_contact = :id_contact LIMIT 1";
            $bddRow = $this->connection->fetchAssociative($sql, ['id_contact' => $idContact]);

            if ($bddRow === false) {
                $stats['not_found']++;
                continue;
            }

            $bddName = trim($bddRow['raison_sociale'] ?? '');

            if ($bddName === $kizeoName) {
                $stats['unchanged']++;
                continue;
            }

            // Nom différent → mettre à jour
            $changes[] = [
                'id_contact' => $idContact,
                'old_name' => $bddName,
                'new_name' => $kizeoName,
            ];

            if (!$dryRun) {
                $this->connection->executeStatement(
                    "UPDATE {$contactTable} SET raison_sociale = :name WHERE id_contact = :id_contact",
                    ['name' => $kizeoName, 'id_contact' => $idContact]
                );
            }

            $stats['updated']++;
        }

        // Afficher les changements
        if (!empty($changes)) {
            $this->io->text(sprintf('  Noms à mettre à jour : %d', count($changes)));
            $sample = array_slice($changes, 0, 10);
            foreach ($sample as $change) {
                $this->io->text(sprintf(
                    '    [%s] "%s" → "%s"',
                    $change['id_contact'],
                    $change['old_name'],
                    $change['new_name']
                ));
            }
            if (count($changes) > 10) {
                $this->io->text(sprintf('    ... et %d autres', count($changes) - 10));
            }
        } else {
            $this->io->text('  Tous les noms sont déjà à jour ✓');
        }

        $this->kizeoLogger->info('Sync client names terminé', [
            'agency' => $agencyCode,
            'list_id' => $listId,
            'dry_run' => $dryRun,
            'stats' => $stats,
        ]);

        return $stats;
    }

    // ──────────────────────────────────────────────────────────────
    //  Parsing items Kizeo
    // ──────────────────────────────────────────────────────────────

    /**
     * Parse les items de la liste clients Kizeo
     * 
     * Format : RAISON_SOCIALE:RAISON_SOCIALE|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE
     * 
     * @return array<int, array{raison_sociale: string, id_contact: string, code_postal: string, ville: string}>
     */
    private function parseClientItems(array $items): array
    {
        $clients = [];

        foreach ($items as $item) {
            $segments = explode('|', $item);

            // Minimum 4 segments nécessaires (raison_sociale, cp, ville, id_contact)
            if (count($segments) < 4) {
                continue;
            }

            $clients[] = [
                'raison_sociale' => $this->extractValue($segments[0]),
                'code_postal' => $this->extractValue($segments[1]),
                'ville' => $this->extractValue($segments[2]),
                'id_contact' => $this->extractValue($segments[3]),
            ];
        }

        return $clients;
    }

    /**
     * Extrait la valeur d'un segment clé:valeur
     * "GLS LAMBALLE:GLS LAMBALLE" → "GLS LAMBALLE"
     */
    private function extractValue(string $segment): string
    {
        $lastColon = strrpos($segment, ':');
        if ($lastColon !== false) {
            return trim(substr($segment, $lastColon + 1));
        }
        return trim($segment);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function getAgenciesToProcess(?string $targetAgency): array
    {
        if ($targetAgency !== null) {
            $agency = $this->agencyRepository->findOneBy(['code' => $targetAgency]);
            return $agency !== null ? [$agency] : [];
        }

        return $this->agencyRepository->findBy(['isActive' => true]);
    }

    private function displayGlobalSummary(array $stats, bool $dryRun): void
    {
        $this->io->newLine();
        $this->io->section('Résumé global');

        $rows = [
            ['Agences traitées', (string) $stats['agencies_processed']],
            ['Agences en erreur', (string) $stats['agencies_failed']],
            ['Noms mis à jour', (string) $stats['total_updated']],
            ['Noms inchangés', (string) $stats['total_unchanged']],
            ['Clients Kizeo non trouvés en BDD', (string) $stats['total_not_found']],
        ];

        $this->io->table(['Métrique', 'Valeur'], $rows);

        if ($dryRun) {
            $this->io->warning('DRY-RUN terminé — aucune modification en BDD');
        } elseif ($stats['agencies_failed'] === 0) {
            $this->io->success('Synchronisation noms clients terminée avec succès');
        } else {
            $this->io->error(sprintf(
                'Synchronisation terminée avec %d erreur(s)',
                $stats['agencies_failed']
            ));
        }
    }
}
