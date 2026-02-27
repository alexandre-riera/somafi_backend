<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service de synchronisation des listes d'équipements BDD → Kizeo.
 *
 * Encapsule le flux complet : GET existant → backup → merge → PUT.
 * Appelable depuis :
 *   - Le controller (après insertion bulk équipements)
 *   - La commande CRON SyncEquipmentListCommand
 *
 * Délègue au KizeoListBuilder :
 *   - fetchActiveEquipments()    → requête BDD équipements actifs
 *   - fetchArchivedKeys()        → clés des équipements complètement archivés
 *   - buildAllItems()            → construction items au format Kizeo API
 *   - extractMergeKeyFromItem()  → extraction clé de merge depuis items Kizeo
 *
 * La logique de merge (ajout/maj/conservation/suppression) est ici.
 *
 * Créé le 27/02/2026 — Phase 3.7 : Sync Kizeo post-insertion bulk
 */
class KizeoEquipmentSyncService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KizeoListBuilder $listBuilder,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $apiToken,
        private readonly string $projectDir,
    ) {
    }

    // =========================================================================
    //  MÉTHODE PUBLIQUE PRINCIPALE — Sync pour une agence
    // =========================================================================

    /**
     * Synchronise la liste d'équipements BDD → Kizeo pour une agence donnée.
     *
     * @param string $agencyCode Code agence (S10, S170…)
     * @param bool   $dryRun     Si true, calcule le merge sans envoyer le PUT
     *
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     dry_run?: bool,
     *     stats: array{
     *         bdd_actifs: int,
     *         kizeo_existants: int,
     *         ajoutes: int,
     *         mis_a_jour: int,
     *         conserves: int,
     *         supprimes: int,
     *         total_envoyes: int,
     *     }
     * }
     */
    public function syncForAgency(string $agencyCode, bool $dryRun = false): array
    {
        $emptyStats = [
            'bdd_actifs'      => 0,
            'kizeo_existants' => 0,
            'ajoutes'         => 0,
            'mis_a_jour'      => 0,
            'conserves'       => 0,
            'supprimes'       => 0,
            'total_envoyes'   => 0,
        ];

        $agencyCode = strtoupper($agencyCode);

        if (!$this->listBuilder->isValidAgencyCode($agencyCode)) {
            return $this->error("Code agence invalide : {$agencyCode}", $emptyStats);
        }

        try {
            // 1. Récupérer le listId Kizeo depuis la table agencies
            $listId = $this->getKizeoListId($agencyCode);
            if ($listId === 0) {
                return $this->error("Pas de liste équipements Kizeo pour {$agencyCode}.", $emptyStats);
            }

            // 2. GET liste Kizeo existante
            $kizeoExistingItems = $this->getKizeoList($listId);
            if ($kizeoExistingItems === null) {
                return $this->error("Impossible de récupérer la liste Kizeo (listId={$listId}).", $emptyStats);
            }

            // 3. Backup local JSON avant PUT
            $this->backupKizeoList($agencyCode, $listId, $kizeoExistingItems);

            // 4. Construction des items BDD via KizeoListBuilder
            //    Retourne map [merge_key => kizeo_item_string]
            $bddItemsMap = $this->listBuilder->buildAllItems($agencyCode);

            // 5. Récupérer les clés archivées via KizeoListBuilder
            //    Retourne map [merge_key => true]
            $archivedKeys = $this->listBuilder->fetchArchivedKeys($agencyCode);

            // 6. Merge intelligent
            $mergeResult = $this->merge($bddItemsMap, $kizeoExistingItems, $archivedKeys);

            $stats = [
                'bdd_actifs'      => count($bddItemsMap),
                'kizeo_existants' => count($kizeoExistingItems),
                'ajoutes'         => $mergeResult['added'],
                'mis_a_jour'      => $mergeResult['updated'],
                'conserves'       => $mergeResult['kept'],
                'supprimes'       => $mergeResult['removed'],
                'total_envoyes'   => count($mergeResult['items']),
            ];

            $this->logger->info("[{$agencyCode}] Merge: {$stats['ajoutes']} ajoutés, {$stats['mis_a_jour']} MAJ, {$stats['conserves']} conservés, {$stats['supprimes']} supprimés");

            // 7. PUT vers Kizeo (sauf dry-run)
            if ($dryRun) {
                $this->logger->info("[{$agencyCode}] DRY-RUN — Aucun PUT envoyé.");
                return ['success' => true, 'error' => null, 'stats' => $stats, 'dry_run' => true];
            }

            $putSuccess = $this->putKizeoList($listId, $mergeResult['items']);
            if (!$putSuccess) {
                return $this->error("Échec du PUT vers Kizeo (listId={$listId}).", $stats);
            }

            $this->logger->info("[{$agencyCode}] ✅ Liste synchronisée — {$stats['total_envoyes']} items envoyés.");

            return ['success' => true, 'error' => null, 'stats' => $stats];

        } catch (\Throwable $e) {
            $this->logger->error("[{$agencyCode}] Erreur sync: {$e->getMessage()}");
            return $this->error("Erreur inattendue: {$e->getMessage()}", $emptyStats);
        }
    }

    // =========================================================================
    //  MERGE — Logique de fusion BDD ↔ Kizeo
    // =========================================================================

    /**
     * Merge intelligent entre items BDD et items Kizeo existants.
     *
     * Règles :
     *   - Équipement BDD existant sur Kizeo     → remplacé par version BDD (MAJ)
     *   - Équipement BDD absent de Kizeo         → ajouté
     *   - Équipement Kizeo absent de BDD          → conservé tel quel (chargé manuellement)
     *   - Équipement archivé en BDD               → retiré de Kizeo
     *
     * @param array<string, string> $bddItemsMap      Map [merge_key => item_string] depuis BDD
     * @param string[]              $kizeoItems        Items bruts depuis GET Kizeo
     * @param array<string, true>   $archivedKeys      Map [merge_key => true] des équipements archivés
     *
     * @return array{items: string[], added: int, updated: int, kept: int, removed: int}
     */
    private function merge(array $bddItemsMap, array $kizeoItems, array $archivedKeys): array
    {
        $added   = 0;
        $updated = 0;
        $kept    = 0;
        $removed = 0;

        // Indexer les items Kizeo existants par clé de merge
        $kizeoIndexed = [];
        foreach ($kizeoItems as $item) {
            $key = $this->listBuilder->extractMergeKeyFromItem($item);
            $kizeoIndexed[$key] = $item;
        }

        // Résultat final : on part d'un array vide et on construit
        $finalItems = [];

        // Étape 1 : Parcourir les items BDD
        foreach ($bddItemsMap as $mergeKey => $bddItem) {
            if (isset($kizeoIndexed[$mergeKey])) {
                // Existe sur Kizeo → mise à jour (remplacer par version BDD)
                $finalItems[] = $bddItem;
                $updated++;
                // Marquer comme traité
                unset($kizeoIndexed[$mergeKey]);
            } else {
                // N'existe pas sur Kizeo → ajout
                $finalItems[] = $bddItem;
                $added++;
            }
        }

        // Étape 2 : Parcourir les items Kizeo restants (non matchés par la BDD)
        foreach ($kizeoIndexed as $mergeKey => $kizeoItem) {
            if (isset($archivedKeys[$mergeKey])) {
                // Archivé en BDD → on le retire (ne pas l'ajouter au résultat)
                $removed++;
            } else {
                // Pas en BDD et pas archivé → conserver tel quel (chargé manuellement)
                $finalItems[] = $kizeoItem;
                $kept++;
            }
        }

        return [
            'items'   => $finalItems,
            'added'   => $added,
            'updated' => $updated,
            'kept'    => $kept,
            'removed' => $removed,
        ];
    }

    // =========================================================================
    //  ACCÈS BDD — Récupération du listId Kizeo
    // =========================================================================

    /**
     * Récupère le kizeo_list_equipments_id depuis la table agencies.
     */
    private function getKizeoListId(string $agencyCode): int
    {
        $sql = 'SELECT kizeo_list_equipments_id FROM agencies WHERE code = :code AND is_active = 1';
        $result = $this->connection->fetchOne($sql, ['code' => $agencyCode]);

        return $result ? (int) $result : 0;
    }

    // =========================================================================
    //  API KIZEO — GET liste existante
    // =========================================================================

    private function getKizeoList(int $listId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->apiUrl}/lists/{$listId}", [
                'headers' => [
                    'Authorization' => $this->apiToken,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error("GET Kizeo list {$listId}: HTTP {$response->getStatusCode()}");
                return null;
            }

            $data = $response->toArray();

            return $data['list']['items'] ?? [];

        } catch (\Throwable $e) {
            $this->logger->error("GET Kizeo list {$listId}: {$e->getMessage()}");
            return null;
        }
    }

    // =========================================================================
    //  API KIZEO — PUT liste mergée
    // =========================================================================

    private function putKizeoList(int $listId, array $items): bool
    {
        try {
            $response = $this->httpClient->request('PUT', "{$this->apiUrl}/lists/{$listId}", [
                'headers' => [
                    'Authorization' => $this->apiToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'items' => $items,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            $this->logger->error("PUT Kizeo list {$listId}: HTTP {$statusCode}");
            return false;

        } catch (\Throwable $e) {
            $this->logger->error("PUT Kizeo list {$listId}: {$e->getMessage()}");
            return false;
        }
    }

    // =========================================================================
    //  BACKUP LOCAL — Sauvegarde JSON avant PUT
    // =========================================================================

    private function backupKizeoList(string $agencyCode, int $listId, array $items): void
    {
        $backupDir = $this->projectDir . '/storage/backups/kizeo_lists';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        // Nettoyage : max 2 backups par agence, max 7 jours
        $this->cleanOldBackups($backupDir, $agencyCode);

        $filename = sprintf(
            'equipements_kizeo_%s_%s.json',
            $agencyCode,
            date('Y-m-d_His')
        );

        $data = [
            'listId'     => $listId,
            'agencyCode' => $agencyCode,
            'date'       => date('c'),
            'count'      => count($items),
            'items'      => $items,
        ];

        file_put_contents(
            $backupDir . '/' . $filename,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $sizeKb = round(strlen(json_encode($data)) / 1024, 1);
        $this->logger->info("Backup: {$filename} ({$sizeKb} KB)");
    }

    private function cleanOldBackups(string $dir, string $agencyCode): void
    {
        $pattern = $dir . "/equipements_kizeo_{$agencyCode}_*.json";
        $files = glob($pattern) ?: [];

        // Supprimer les fichiers > 7 jours
        $sevenDaysAgo = time() - (7 * 24 * 3600);
        $remaining = [];
        foreach ($files as $file) {
            if (filemtime($file) < $sevenDaysAgo) {
                unlink($file);
            } else {
                $remaining[] = $file;
            }
        }

        // Garder max 2 backups par agence
        if (count($remaining) > 2) {
            usort($remaining, fn($a, $b) => filemtime($a) - filemtime($b));
            $toDelete = array_slice($remaining, 0, count($remaining) - 2);
            foreach ($toDelete as $file) {
                unlink($file);
            }
        }
    }

    // =========================================================================
    //  HELPER
    // =========================================================================

    private function error(string $message, array $stats): array
    {
        $this->logger->error($message);
        return ['success' => false, 'error' => $message, 'stats' => $stats];
    }
}
