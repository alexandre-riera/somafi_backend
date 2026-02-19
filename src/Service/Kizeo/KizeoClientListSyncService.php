<?php

namespace App\Service\Kizeo;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use App\Service\Kizeo\KizeoApiService;

/**
 * Phase 2.3 — Synchronisation liste clients BDD → Kizeo.
 * 
 * Déclenché immédiatement après la création d'un client via le formulaire.
 * Stratégie : GET backup → vérification collision → ajout → PUT liste complète.
 * 
 * IMPORTANT : Kizeo écrase toute la liste à chaque PUT.
 * Il faut donc TOUJOURS envoyer la liste complète (existants + nouveau).
 * 
 * Format liste clients Kizeo :
 * "NOM:NOM|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE"
 */
class KizeoClientListSyncService
{
    private const BACKUP_DIR = 'storage/backups/kizeo_lists';
    private const BACKUP_MAX_PER_AGENCY = 2;
    private const BACKUP_RETENTION_DAYS = 7;

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
        private readonly string $projectDir,
    ) {}

    /**
     * Synchronise un seul client vers la liste Kizeo de l'agence.
     * Appelé immédiatement après insertContact() dans le controller.
     *
     * IMPORTANT : Kizeo écrase la liste à chaque PUT, donc on GET la liste
     * complète, on ajoute le nouveau client, et on PUT le tout.
     *
     * @param string $agencyCode Code agence (ex: S100, S170)
     * @param int    $contactId  ID BDD du contact (lastInsertId)
     *
     * @return array{success: bool, error: ?string}
     */
    public function syncNewClient(string $agencyCode, int $contactId): array
    {
        $agencyCode = strtoupper($agencyCode);

        // 1. Récupérer le listId Kizeo de l'agence
        $listId = $this->getAgencyKizeoListClientsId($agencyCode);
        if (!$listId) {
            $this->kizeoLogger->warning('[ClientListSync] Agence sans kizeo_list_clients_id', [
                'agency' => $agencyCode,
            ]);
            return ['success' => false, 'error' => 'Agence non configurée pour Kizeo.'];
        }

        // 2. Récupérer les données du contact depuis la BDD
        $tableName = 'contact_' . strtolower($agencyCode);

        try {
            $contact = $this->connection->fetchAssociative(
                "SELECT id, raison_sociale, cpostalp, villep, id_contact, id_societe 
                 FROM {$tableName} WHERE id = :id",
                ['id' => $contactId]
            );
        } catch (\Exception $e) {
            $this->kizeoLogger->error('[ClientListSync] Erreur lecture BDD', [
                'agency' => $agencyCode,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => 'Erreur lecture base de données.'];
        }

        if (!$contact) {
            $this->kizeoLogger->error('[ClientListSync] Contact non trouvé en BDD', [
                'agency' => $agencyCode,
                'contact_id' => $contactId,
            ]);
            return ['success' => false, 'error' => 'Contact introuvable en base de données.'];
        }

        $idContactValue = trim((string) ($contact['id_contact'] ?? ''));

        // 3. Vérification : id_contact est obligatoire pour la sync Kizeo
        if ($idContactValue === '') {
            $this->kizeoLogger->error('[ClientListSync] id_contact manquant, sync impossible', [
                'agency' => $agencyCode,
                'contact_id' => $contactId,
                'raison_sociale' => $contact['raison_sociale'],
            ]);
            return [
                'success' => false,
                'error' => 'L\'identifiant client (id_contact) est obligatoire pour la synchronisation Kizeo.',
            ];
        }

        // 4. GET la liste clients Kizeo existante
        $kizeoResponse = $this->kizeoApi->getList($listId);
        if (!$kizeoResponse) {
            $this->kizeoLogger->error('[ClientListSync] Impossible de récupérer la liste Kizeo', [
                'list_id' => $listId,
                'agency' => $agencyCode,
            ]);
            return ['success' => false, 'error' => 'Impossible de récupérer la liste clients Kizeo.'];
        }

        $existingItems = $kizeoResponse['list']['items'] ?? [];

        $this->kizeoLogger->info('[ClientListSync] Liste Kizeo récupérée', [
            'agency' => $agencyCode,
            'list_id' => $listId,
            'existing_count' => count($existingItems),
        ]);

        // 5. Backup persistant AVANT toute modification
        $this->saveBackup($agencyCode, $kizeoResponse);

        // 6. Vérification collision : id_contact existe déjà dans la liste Kizeo ?
        //    → BLOQUANT : on ne remplace JAMAIS silencieusement un client existant
        foreach ($existingItems as $item) {
            $existingIdContact = $this->extractIdContactFromItem($item);
            if ($existingIdContact !== null && $existingIdContact === $idContactValue) {
                $existingName = $this->extractRaisonSocialeFromItem($item);

                $this->kizeoLogger->warning('[ClientListSync] Collision id_contact détectée', [
                    'agency' => $agencyCode,
                    'id_contact' => $idContactValue,
                    'existing_name' => $existingName,
                    'new_name' => $contact['raison_sociale'],
                ]);

                return [
                    'success' => false,
                    'error' => sprintf(
                        'Le client "%s" (id_contact: %s) existe déjà dans la liste clients de l\'agence %s. '
                        . 'Veuillez vérifier l\'identifiant saisi.',
                        $existingName,
                        $idContactValue,
                        $agencyCode
                    ),
                ];
            }
        }

        // 7. Pas de collision → Ajout du nouveau client en fin de liste
        $newItem = $this->buildClientItem($contact, $agencyCode);
        $existingItems[] = $newItem;

        $this->kizeoLogger->info('[ClientListSync] Nouveau client ajouté à la liste', [
            'agency' => $agencyCode,
            'id_contact' => $idContactValue,
            'raison_sociale' => $contact['raison_sociale'],
            'new_total' => count($existingItems),
        ]);

        // 8. PUT la liste complète (Kizeo écrase tout à chaque PUT)
        $success = $this->kizeoApi->updateList($listId, $existingItems);

        if ($success) {
            $this->kizeoLogger->info('[ClientListSync] PUT réussi', [
                'agency' => $agencyCode,
                'list_id' => $listId,
                'total_items' => count($existingItems),
            ]);
        } else {
            $this->kizeoLogger->error('[ClientListSync] PUT échoué', [
                'agency' => $agencyCode,
                'list_id' => $listId,
            ]);
        }

        return [
            'success' => $success,
            'error' => $success ? null : 'Échec de l\'envoi vers Kizeo (PUT).',
        ];
    }

    // =========================================================================
    //  BACKUP
    // =========================================================================

    /**
     * Sauvegarde la liste Kizeo actuelle en JSON avant modification.
     * Rétention : max 2 backups par agence, max 7 jours.
     */
    private function saveBackup(string $agencyCode, array $kizeoResponse): void
    {
        $backupDir = $this->projectDir . '/' . self::BACKUP_DIR;

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Nettoyage des anciens backups
        $this->cleanBackups($backupDir, $agencyCode);

        // Sauvegarde
        $filename = sprintf(
            'clients_%s_%s.json',
            $agencyCode,
            date('Y-m-d_His')
        );

        $filepath = $backupDir . '/' . $filename;

        file_put_contents(
            $filepath,
            json_encode($kizeoResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->kizeoLogger->info('[ClientListSync] Backup sauvegardé', [
            'file' => $filename,
            'items_count' => count($kizeoResponse['list']['items'] ?? []),
        ]);
    }

    /**
     * Nettoie les backups : supprime ceux > 7 jours et garde max 2 par agence.
     */
    private function cleanBackups(string $backupDir, string $agencyCode): void
    {
        $pattern = sprintf('clients_%s_*.json', $agencyCode);
        $files = glob($backupDir . '/' . $pattern);

        if (!$files) {
            return;
        }

        // Trier par date de modification (plus récent en premier)
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $retentionLimit = time() - (self::BACKUP_RETENTION_DAYS * 86400);

        foreach ($files as $index => $file) {
            $shouldDelete = false;

            // Supprimer si > 7 jours
            if (filemtime($file) < $retentionLimit) {
                $shouldDelete = true;
            }

            // Supprimer si au-delà du max par agence
            if ($index >= self::BACKUP_MAX_PER_AGENCY) {
                $shouldDelete = true;
            }

            if ($shouldDelete && file_exists($file)) {
                unlink($file);
                $this->kizeoLogger->debug('[ClientListSync] Backup supprimé', [
                    'file' => basename($file),
                ]);
            }
        }
    }

    // =========================================================================
    //  FORMAT KIZEO
    // =========================================================================

    /**
     * Construit un item Kizeo au format attendu pour la liste clients.
     * Format : "NOM:NOM|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE"
     */
    private function buildClientItem(array $contact, string $agencyCode): string
    {
        $nom = trim($contact['raison_sociale'] ?? '');
        $cp = trim($contact['cpostalp'] ?? '');
        $ville = trim($contact['villep'] ?? '');
        $idContact = trim((string) ($contact['id_contact'] ?? ''));
        $idSociete = trim((string) ($contact['id_societe'] ?? ''));

        $segments = [
            sprintf('%s:%s', $nom, $nom),
            sprintf('%s:%s', $cp, $cp),
            sprintf('%s:%s', $ville, $ville),
            sprintf('%s:%s', $idContact, $idContact),
            sprintf('%s:%s', $agencyCode, $agencyCode),
            // id_societe : "val:val" si renseigné, ":" si vide/null/0
            ($idSociete !== '' && $idSociete !== '0')
                ? sprintf('%s:%s', $idSociete, $idSociete)
                : ':',
        ];

        return implode('|', $segments);
    }

    /**
     * Extrait l'id_contact depuis un item Kizeo existant.
     * L'id_contact est dans le segment 3 (index 3), partie après le ":".
     */
    private function extractIdContactFromItem(string $item): ?string
    {
        $segments = explode('|', $item);

        if (!isset($segments[3])) {
            return null;
        }

        $parts = explode(':', $segments[3], 2);
        $value = isset($parts[1]) ? trim($parts[1]) : null;

        return ($value !== null && $value !== '') ? $value : null;
    }

    /**
     * Extrait la raison_sociale depuis un item Kizeo existant.
     * La raison_sociale est dans le segment 0 (index 0), partie après le ":".
     */
    private function extractRaisonSocialeFromItem(string $item): string
    {
        $segments = explode('|', $item);

        if (!isset($segments[0])) {
            return '(inconnu)';
        }

        $parts = explode(':', $segments[0], 2);

        return isset($parts[1]) ? trim($parts[1]) : '(inconnu)';
    }

    // =========================================================================
    //  AGENCE
    // =========================================================================

    /**
     * Récupère le kizeo_list_clients_id depuis la table agencies.
     */
    private function getAgencyKizeoListClientsId(string $agencyCode): ?int
    {
        $result = $this->connection->fetchAssociative(
            'SELECT kizeo_list_clients_id FROM agencies WHERE code = :code AND is_active = 1',
            ['code' => $agencyCode]
        );

        if (!$result || !$result['kizeo_list_clients_id']) {
            return null;
        }

        return (int) $result['kizeo_list_clients_id'];
    }
}
