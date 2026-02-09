<?php

declare(strict_types=1);

namespace App\Command\Kizeo;

use App\Repository\AgencyRepository;
use App\Service\Kizeo\KizeoApiService;
use App\Service\Kizeo\KizeoListBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Commande de synchronisation des listes d'équipements BDD → Kizeo External Lists
 * 
 * La BDD SOMAFI est maître. Cette commande :
 * 1. GET la liste Kizeo existante (backup local)
 * 2. Récupère les équipements actifs en BDD
 * 3. Merge intelligent (BDD gagne, Kizeo-only conservés, archivés supprimés)
 * 4. PUT la liste mergée vers Kizeo
 * 
 * Stratégie MERGE (pas de remplacement complet) :
 * - Équipement BDD existant sur Kizeo → remplacé par la version BDD
 * - Équipement BDD absent de Kizeo → ajouté
 * - Équipement Kizeo absent de BDD → conservé (chargé manuellement, pas encore de visite)
 * - Équipement archivé en BDD (is_archive=1) → retiré de Kizeo
 * 
 * Usage :
 *   php bin/console app:kizeo:sync-equipment-list                     # Toutes les agences
 *   php bin/console app:kizeo:sync-equipment-list --agency=S170       # Une seule agence
 *   php bin/console app:kizeo:sync-equipment-list --dry-run           # Simulation (pas de PUT)
 *   php bin/console app:kizeo:sync-equipment-list --agency=S170 --dry-run
 * 
 * CRON recommandé : toutes les heures ou quotidien
 *   0 * * * * cd /path/to/project && php bin/console app:kizeo:sync-equipment-list >> var/log/sync-list.log 2>&1
 * 
 * Créé le 08/02/2026 — Phase C : Synchro Kizeo External Lists
 */
#[AsCommand(
    name: 'app:kizeo:sync-equipment-list',
    description: 'Synchronise les listes d\'équipements BDD → Kizeo External Lists (merge intelligent)',
)]
class SyncEquipmentListCommand extends Command
{
    /** Répertoire des backups */
    private const BACKUP_DIR = 'storage/backups/kizeo_lists';

    /** Nombre maximum de backups par agence */
    private const MAX_BACKUPS_PER_AGENCY = 2;

    /** Durée maximale de rétention d'un backup (en jours) */
    private const BACKUP_RETENTION_DAYS = 7;

    /** Pause entre chaque agence (éviter le rate limiting) */
    private const AGENCY_DELAY_MS = 500_000; // 500ms

    private SymfonyStyle $io;
    private string $projectDir;

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly KizeoListBuilder $listBuilder,
        private readonly AgencyRepository $agencyRepository,
        private readonly LoggerInterface $kizeoLogger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $kernelProjectDir,
    ) {
        parent::__construct();
        $this->projectDir = $kernelProjectDir;
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence à cibler (ex: S170)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : prévisualise sans envoyer à Kizeo')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $targetAgency = $input->getOption('agency');

        $this->io->title('Synchronisation Listes Équipements → Kizeo');

        if ($dryRun) {
            $this->io->warning('MODE DRY-RUN : aucun PUT ne sera envoyé à Kizeo');
        }

        // Valider l'agence si spécifiée
        if ($targetAgency !== null) {
            $targetAgency = strtoupper($targetAgency);
            if (!$this->listBuilder->isValidAgencyCode($targetAgency)) {
                $this->io->error(sprintf('Code agence invalide : %s', $targetAgency));
                return Command::FAILURE;
            }
        }

        // Nettoyage des anciens backups
        $this->cleanupOldBackups();

        // Récupérer les agences à traiter
        $agencies = $this->getAgenciesToProcess($targetAgency);

        if (empty($agencies)) {
            $this->io->warning('Aucune agence à traiter (vérifier kizeo_list_equipments_id en BDD)');
            return Command::SUCCESS;
        }

        $this->io->info(sprintf('Agences à traiter : %d', count($agencies)));

        // Traiter chaque agence
        $globalStats = [
            'agencies_processed' => 0,
            'agencies_failed' => 0,
            'total_added' => 0,
            'total_updated' => 0,
            'total_kept' => 0,
            'total_removed' => 0,
            'total_items_sent' => 0,
        ];

        foreach ($agencies as $agency) {
            $code = $agency->getCode();
            $listId = $agency->getKizeoListEquipmentsId();

            if ($listId === null || $listId === 0) {
                $this->io->note(sprintf('[%s] Pas de kizeo_list_equipments_id → skip', $code));
                continue;
            }

            $this->io->section(sprintf('Agence %s — %s (listId=%d)', $code, $agency->getNom(), $listId));

            try {
                $stats = $this->syncAgency($code, $listId, $dryRun);
                $globalStats['agencies_processed']++;
                $globalStats['total_added'] += $stats['added'];
                $globalStats['total_updated'] += $stats['updated'];
                $globalStats['total_kept'] += $stats['kept'];
                $globalStats['total_removed'] += $stats['removed'];
                $globalStats['total_items_sent'] += $stats['total_sent'];
            } catch (\Throwable $e) {
                $globalStats['agencies_failed']++;
                $this->io->error(sprintf('[%s] ERREUR : %s', $code, $e->getMessage()));
                $this->kizeoLogger->error('Erreur sync agence', [
                    'agency' => $code,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
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
     * Synchronise une agence : GET → backup → merge → PUT
     * 
     * @return array{added: int, updated: int, kept: int, removed: int, total_sent: int}
     */
    private function syncAgency(string $agencyCode, int $listId, bool $dryRun): array
    {
        // ── Étape 1 : GET liste Kizeo existante ──
        $this->io->text('→ GET liste Kizeo existante...');
        $kizeoResponse = $this->kizeoApi->getList($listId);

        if ($kizeoResponse === null) {
            throw new \RuntimeException(sprintf('Impossible de récupérer la liste Kizeo (listId=%d)', $listId));
        }

        $existingItems = $kizeoResponse['list']['items'] ?? [];
        $this->io->text(sprintf('  Items existants sur Kizeo : %d', count($existingItems)));

        // ── Étape 2 : Backup ──
        $this->backupList($agencyCode, $kizeoResponse);

        // ── Étape 3 : Récupérer les données BDD ──
        $this->io->text('→ Construction des items depuis la BDD...');
        $bddItems = $this->listBuilder->buildAllItems($agencyCode);
        $archivedKeys = $this->listBuilder->fetchArchivedKeys($agencyCode);

        $this->io->text(sprintf('  Équipements actifs en BDD : %d', count($bddItems)));
        $this->io->text(sprintf('  Clés archivées en BDD : %d', count($archivedKeys)));

        // ── Étape 4 : Merge ──
        $this->io->text('→ Merge en cours...');
        $mergeResult = $this->mergeItems($existingItems, $bddItems, $archivedKeys);

        $stats = $mergeResult['stats'];
        $mergedItems = $mergeResult['items'];

        $this->io->text(sprintf(
            '  Résultat : %d ajoutés, %d mis à jour, %d conservés Kizeo, %d supprimés',
            $stats['added'], $stats['updated'], $stats['kept'], $stats['removed']
        ));
        $this->io->text(sprintf('  Total items à envoyer : %d', count($mergedItems)));

        // ── Étape 5 : PUT (sauf dry-run) ──
        if ($dryRun) {
            $this->io->note(sprintf('[DRY-RUN] PUT ignoré — %d items auraient été envoyés', count($mergedItems)));

            // En dry-run, afficher un échantillon des changements
            $this->displaySample($mergeResult, $agencyCode);
        } else {
            $this->io->text('→ PUT liste vers Kizeo...');
            $success = $this->kizeoApi->updateList($listId, $mergedItems);

            if (!$success) {
                throw new \RuntimeException(sprintf('Échec du PUT vers Kizeo (listId=%d)', $listId));
            }

            $this->io->success(sprintf('[%s] ✅ Liste synchronisée — %d items envoyés', $agencyCode, count($mergedItems)));
        }

        $this->kizeoLogger->info('Sync agence terminée', [
            'agency' => $agencyCode,
            'list_id' => $listId,
            'dry_run' => $dryRun,
            'stats' => $stats,
            'total_sent' => count($mergedItems),
        ]);

        $stats['total_sent'] = count($mergedItems);
        return $stats;
    }

    // ──────────────────────────────────────────────────────────────
    //  Logique de merge (C.4)
    // ──────────────────────────────────────────────────────────────

    /**
     * Fusionne les items Kizeo existants avec les items BDD
     * 
     * Règles :
     * - BDD existant sur Kizeo → remplacé par version BDD
     * - BDD absent de Kizeo → ajouté  
     * - Kizeo absent de BDD → conservé (chargé manuellement)
     * - Archivé en BDD + présent sur Kizeo → supprimé
     * 
     * @param string[]              $existingItems Items Kizeo actuels
     * @param array<string, string> $bddItems      Map [clé_merge => item_kizeo] depuis BDD
     * @param array<string, true>   $archivedKeys  Map [clé_merge => true] pour les archivés
     * 
     * @return array{items: string[], stats: array{added: int, updated: int, kept: int, removed: int}, details: array<string, string[]>}
     */
    private function mergeItems(array $existingItems, array $bddItems, array $archivedKeys): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'kept' => 0, 'removed' => 0];
        $details = ['added' => [], 'updated' => [], 'removed' => []];
        $result = [];

        // Map des clés BDD déjà traitées (pour savoir ce qui reste à ajouter)
        $bddKeysProcessed = [];

        // ── Passe 1 : parcourir les items Kizeo existants ──
        foreach ($existingItems as $existingItem) {
            $key = $this->listBuilder->extractMergeKeyFromItem($existingItem);

            // Cas 1 : archivé en BDD → supprimer de Kizeo
            if (isset($archivedKeys[$key])) {
                $stats['removed']++;
                $details['removed'][] = $key;
                continue; // ne pas inclure dans le résultat
            }

            // Cas 2 : existe en BDD → remplacer par version BDD
            if (isset($bddItems[$key])) {
                $result[] = $bddItems[$key];
                $bddKeysProcessed[$key] = true;
                $stats['updated']++;
                $details['updated'][] = $key;
                continue;
            }

            // Cas 3 : absent de BDD → conserver tel quel (chargé manuellement)
            $result[] = $existingItem;
            $stats['kept']++;
        }

        // ── Passe 2 : ajouter les items BDD non encore présents ──
        foreach ($bddItems as $key => $item) {
            if (!isset($bddKeysProcessed[$key])) {
                $result[] = $item;
                $stats['added']++;
                $details['added'][] = $key;
            }
        }

        return [
            'items' => $result,
            'stats' => $stats,
            'details' => $details,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Backup (C.3)
    // ──────────────────────────────────────────────────────────────

    /**
     * Sauvegarde la liste Kizeo existante avant le PUT
     */
    private function backupList(string $agencyCode, array $kizeoResponse): void
    {
        $backupDir = $this->getBackupDir();

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = sprintf(
            'equipements_kizeo_%s_%s.json',
            $agencyCode,
            date('Y-m-d_His')
        );
        $filepath = $backupDir . '/' . $filename;

        $written = file_put_contents(
            $filepath,
            json_encode($kizeoResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($written === false) {
            $this->kizeoLogger->warning('Échec écriture backup', ['filepath' => $filepath]);
            $this->io->warning(sprintf('⚠ Backup échoué : %s', $filepath));
        } else {
            $this->io->text(sprintf('  Backup : %s (%s)', $filename, $this->formatBytes($written)));
        }

        // Nettoyage rétention par agence (garder max 2)
        $this->cleanupAgencyBackups($agencyCode);
    }

    // ──────────────────────────────────────────────────────────────
    //  Nettoyage backups (C.5)
    // ──────────────────────────────────────────────────────────────

    /**
     * Supprime les backups de plus de 7 jours (toutes agences)
     */
    private function cleanupOldBackups(): void
    {
        $backupDir = $this->getBackupDir();

        if (!is_dir($backupDir)) {
            return;
        }

        $cutoff = time() - (self::BACKUP_RETENTION_DAYS * 86400);
        $deleted = 0;

        $files = glob($backupDir . '/equipements_kizeo_*.json') ?: [];
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->io->text(sprintf('🗑 %d backup(s) expirés supprimés (> %d jours)', $deleted, self::BACKUP_RETENTION_DAYS));
            $this->kizeoLogger->info('Backups expirés supprimés', ['count' => $deleted]);
        }
    }

    /**
     * Conserve maximum N backups par agence (les plus récents)
     */
    private function cleanupAgencyBackups(string $agencyCode): void
    {
        $backupDir = $this->getBackupDir();
        $pattern = sprintf('%s/equipements_kizeo_%s_*.json', $backupDir, $agencyCode);
        $files = glob($pattern) ?: [];

        if (count($files) <= self::MAX_BACKUPS_PER_AGENCY) {
            return;
        }

        // Trier par date de modification (plus récent en premier)
        usort($files, fn(string $a, string $b) => filemtime($b) - filemtime($a));

        // Supprimer les excédents
        $toDelete = array_slice($files, self::MAX_BACKUPS_PER_AGENCY);
        foreach ($toDelete as $file) {
            unlink($file);
            $this->kizeoLogger->debug('Backup excédentaire supprimé', ['file' => basename($file)]);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Récupère les agences à traiter
     * 
     * @return array<\App\Entity\Agency>
     */
    private function getAgenciesToProcess(?string $targetAgency): array
    {
        if ($targetAgency !== null) {
            $agency = $this->agencyRepository->findOneBy(['code' => $targetAgency]);
            return $agency !== null ? [$agency] : [];
        }

        // Toutes les agences actives avec un kizeo_list_equipments_id
        return $this->agencyRepository->findBy(['isActive' => true]);
    }

    /**
     * Affiche un échantillon des changements en mode dry-run
     */
    private function displaySample(array $mergeResult, string $agencyCode): void
    {
        $details = $mergeResult['details'];

        if (!empty($details['added'])) {
            $sample = array_slice($details['added'], 0, 5);
            $this->io->text('  📥 Exemples ajouts :');
            foreach ($sample as $key) {
                $this->io->text(sprintf('     + %s', $key));
            }
            if (count($details['added']) > 5) {
                $this->io->text(sprintf('     ... et %d autres', count($details['added']) - 5));
            }
        }

        if (!empty($details['updated'])) {
            $sample = array_slice($details['updated'], 0, 5);
            $this->io->text('  🔄 Exemples mises à jour :');
            foreach ($sample as $key) {
                $this->io->text(sprintf('     ~ %s', $key));
            }
            if (count($details['updated']) > 5) {
                $this->io->text(sprintf('     ... et %d autres', count($details['updated']) - 5));
            }
        }

        if (!empty($details['removed'])) {
            $sample = array_slice($details['removed'], 0, 5);
            $this->io->text('  ❌ Exemples suppressions :');
            foreach ($sample as $key) {
                $this->io->text(sprintf('     - %s', $key));
            }
            if (count($details['removed']) > 5) {
                $this->io->text(sprintf('     ... et %d autres', count($details['removed']) - 5));
            }
        }
    }

    /**
     * Affiche le résumé global
     */
    private function displayGlobalSummary(array $stats, bool $dryRun): void
    {
        $this->io->newLine();
        $this->io->section('Résumé global');

        $rows = [
            ['Agences traitées', (string) $stats['agencies_processed']],
            ['Agences en erreur', (string) $stats['agencies_failed']],
            ['Items ajoutés', (string) $stats['total_added']],
            ['Items mis à jour', (string) $stats['total_updated']],
            ['Items conservés (Kizeo-only)', (string) $stats['total_kept']],
            ['Items supprimés (archivés)', (string) $stats['total_removed']],
            ['Total items envoyés', (string) $stats['total_items_sent']],
        ];

        $this->io->table(['Métrique', 'Valeur'], $rows);

        if ($dryRun) {
            $this->io->warning('DRY-RUN terminé — aucune modification envoyée à Kizeo');
        } elseif ($stats['agencies_failed'] === 0) {
            $this->io->success('Synchronisation terminée avec succès');
        } else {
            $this->io->error(sprintf(
                'Synchronisation terminée avec %d erreur(s)',
                $stats['agencies_failed']
            ));
        }
    }

    private function getBackupDir(): string
    {
        return $this->projectDir . '/' . self::BACKUP_DIR;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
