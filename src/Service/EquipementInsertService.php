<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service d'insertion en masse des équipements dans equipement_sXX.
 *
 * Tâche 3.6 : Insertion batch performante par chunks de 100 lignes via DBAL natif.
 */
class EquipementInsertService
{
    private const CHUNK_SIZE = 100;

    private const VALID_AGENCIES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80', 'S100',
        'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    // =========================================================================
    //  INSERTION BATCH
    // =========================================================================

    /**
     * Insère un tableau de lignes d'équipements dans equipement_sXX par chunks.
     *
     * @param string $agencyCode Code agence (ex: S170)
     * @param array<int, array<string, mixed>> $rows Lignes à insérer
     * @return array{inserted: int, errors: string[]}
     */
    public function insertBatch(string $agencyCode, array $rows): array
    {
        $table = $this->getEquipementTableName($agencyCode);
        $inserted = 0;
        $errors = [];

        if (empty($rows)) {
            return ['inserted' => 0, 'errors' => ['Aucune ligne à insérer.']];
        }

        $chunks = array_chunk($rows, self::CHUNK_SIZE);
        $totalChunks = count($chunks);

        $this->logger->info('[EquipementInsert] Démarrage insertion batch.', [
            'agency' => $agencyCode,
            'table'  => $table,
            'total'  => count($rows),
            'chunks' => $totalChunks,
        ]);

        $this->connection->beginTransaction();

        try {
            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkInserted = $this->insertChunk($table, $chunk);
                $inserted += $chunkInserted;

                $this->logger->debug('[EquipementInsert] Chunk traité.', [
                    'chunk'    => $chunkIndex + 1,
                    'total'    => $totalChunks,
                    'inserted' => $chunkInserted,
                ]);
            }

            $this->connection->commit();

            $this->logger->info('[EquipementInsert] Insertion batch terminée.', [
                'agency'   => $agencyCode,
                'inserted' => $inserted,
            ]);
        } catch (\Exception $e) {
            $this->connection->rollBack();

            $errorMsg = sprintf('Erreur insertion batch : %s', $e->getMessage());
            $errors[] = $errorMsg;

            $this->logger->error('[EquipementInsert] Rollback après erreur.', [
                'agency' => $agencyCode,
                'error'  => $e->getMessage(),
            ]);
        }

        return ['inserted' => $inserted, 'errors' => $errors];
    }

    /**
     * Insère un chunk de lignes via INSERT multi-valeurs.
     */
    private function insertChunk(string $table, array $chunk): int
    {
        if (empty($chunk)) {
            return 0;
        }

        $columns = [
            'id_contact',
            'numero_equipement',
            'libelle_equipement',
            'visite',
            'annee',
            'marque',
            'mode_fonctionnement',
            'repere_site_client',
            'is_hors_contrat',
            'is_archive',
            'date_enregistrement',
        ];

        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            $placeholders
        );

        $params = [];
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($chunk as $row) {
            $params[] = (int) $row['id_contact'];
            $params[] = (string) ($row['numero_equipement'] ?? '');
            $params[] = (string) ($row['libelle_equipement'] ?? '');
            $params[] = (string) ($row['visite'] ?? 'CEA');
            $params[] = (string) ($row['annee'] ?? date('Y'));
            $params[] = (string) ($row['marque'] ?? '');
            $params[] = (string) ($row['mode_fonctionnement'] ?? '');
            $params[] = (string) ($row['repere_site_client'] ?? '');
            $params[] = (int) ($row['is_hors_contrat'] ?? 0);
            $params[] = (int) ($row['is_archive'] ?? 0);
            $params[] = $now;
        }

        return $this->connection->executeStatement($sql, $params);
    }

    // =========================================================================
    //  VÉRIFICATION DES DOUBLONS
    // =========================================================================

    /**
     * Vérifie les doublons potentiels dans la BDD pour un contact donné.
     *
     * Clé de déduplication : numero_equipement + visite + annee + id_contact
     *
     * @param string $agencyCode Code agence
     * @param string $idContact  id_contact métier
     * @param string $annee      Année de référence
     * @param array  $rows       Lignes à vérifier
     *
     * @return array{
     *     duplicates: array<int, array<string, mixed>>,
     *     clean: array<int, array<string, mixed>>
     * }
     */
    public function checkDuplicates(
        string $agencyCode,
        string $idContact,
        string $annee,
        array $rows,
    ): array {
        $table = $this->getEquipementTableName($agencyCode);

        // Récupérer tous les équipements existants pour ce contact + année
        $sql = sprintf(
            'SELECT numero_equipement, visite FROM %s WHERE id_contact = :id_contact AND annee = :annee AND is_archive = 0',
            $table
        );

        $existing = $this->connection->fetchAllAssociative($sql, [
            'id_contact' => $idContact,
            'annee'      => $annee,
        ]);

        // Construire un set des clés existantes
        $existingKeys = [];
        foreach ($existing as $row) {
            $key = $row['numero_equipement'] . '|' . $row['visite'];
            $existingKeys[$key] = true;
        }

        $duplicates = [];
        $clean = [];

        foreach ($rows as $row) {
            $key = ($row['numero_equipement'] ?? '') . '|' . ($row['visite'] ?? '');
            if (isset($existingKeys[$key])) {
                $duplicates[] = $row;
            } else {
                $clean[] = $row;
            }
        }

        if (!empty($duplicates)) {
            $this->logger->warning('[EquipementInsert] Doublons détectés.', [
                'agency'     => $agencyCode,
                'id_contact' => $idContact,
                'annee'      => $annee,
                'count'      => count($duplicates),
            ]);
        }

        return ['duplicates' => $duplicates, 'clean' => $clean];
    }

    // =========================================================================
    //  COMPTAGE EXISTANT
    // =========================================================================

    /**
     * Compte les équipements existants d'un contact pour une année donnée.
     */
    public function countExistingEquipements(
        string $agencyCode,
        string $idContact,
        string $annee,
    ): int {
        $table = $this->getEquipementTableName($agencyCode);

        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE id_contact = :id_contact AND annee = :annee AND is_archive = 0', $table),
            ['id_contact' => $idContact, 'annee' => $annee]
        );
    }

    /**
     * Récupère les équipements existants d'un contact (pour affichage dans preview).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExistingEquipements(
        string $agencyCode,
        string $idContact,
        ?string $annee = null,
    ): array {
        $table = $this->getEquipementTableName($agencyCode);

        $sql = sprintf(
            'SELECT numero_equipement, libelle_equipement, visite, annee, marque, mode_fonctionnement '
            . 'FROM %s WHERE id_contact = :id_contact AND is_archive = 0',
            $table
        );
        $params = ['id_contact' => $idContact];

        if ($annee) {
            $sql .= ' AND annee = :annee';
            $params['annee'] = $annee;
        }

        $sql .= ' ORDER BY numero_equipement, visite';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    // =========================================================================
    //  RÉSOLUTION TABLE
    // =========================================================================

    private function getEquipementTableName(string $agencyCode): string
    {
        $code = strtoupper($agencyCode);

        if (!in_array($code, self::VALID_AGENCIES, true)) {
            throw new \InvalidArgumentException(sprintf('Code agence invalide : %s', $agencyCode));
        }

        return 'equipement_' . strtolower($code);
    }
}
