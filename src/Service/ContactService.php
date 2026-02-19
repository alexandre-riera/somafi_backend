<?php

namespace App\Service;

use App\DTO\ContactDTO;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Service pour la gestion des clients (contacts) sur les 13 agences SOMAFI.
 * 
 * Gère l'insertion, la mise à jour et la recherche dans les tables
 * contact_sXX dynamiquement résolues à partir du code agence.
 */
class ContactService
{
    private const VALID_AGENCY_CODES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80',
        'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─────────────────────────────────────────────────────────
    // Résolution dynamique de table
    // ─────────────────────────────────────────────────────────

    /**
     * Résout le nom de la table contact à partir du code agence.
     * 
     * @throws \InvalidArgumentException si le code agence est invalide
     */
    public function getContactTableName(string $agencyCode): string
    {
        $agencyCode = strtoupper(trim($agencyCode));

        if (!in_array($agencyCode, self::VALID_AGENCY_CODES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Code agence invalide : "%s". Codes autorisés : %s',
                    $agencyCode,
                    implode(', ', self::VALID_AGENCY_CODES)
                )
            );
        }

        return 'contact_' . strtolower($agencyCode);
    }

    // ─────────────────────────────────────────────────────────
    // Insertion
    // ─────────────────────────────────────────────────────────

    /**
     * Insère un nouveau client dans la table contact_sXX de l'agence cible.
     * 
     * Workflow :
     * 1. Résolution dynamique de la table
     * 2. Vérification unicité id_contact (si renseigné)
     * 3. Nettoyage des valeurs nulles
     * 4. Insertion DBAL
     * 5. Retour du lastInsertId
     * 
     * @param string     $agencyCode Code agence (ex: 'S100')
     * @param ContactDTO $dto        DTO rempli par le formulaire
     * 
     * @return int L'ID du client nouvellement créé (PK auto-increment)
     * 
     * @throws \InvalidArgumentException si le code agence est invalide
     * @throws \RuntimeException         si le id_contact existe déjà sur cette agence
     * @throws \RuntimeException         si l'insertion échoue
     */
    public function insertContact(string $agencyCode, ContactDTO $dto): int
    {
        $tableName = $this->getContactTableName($agencyCode);

        // ── Vérification unicité id_contact ──────────────────
        if (!empty($dto->idContact)) {
            $existing = $this->findByIdContact($agencyCode, $dto->idContact);
            if ($existing !== null) {
                throw new \RuntimeException(
                    sprintf(
                        'Un client avec l\'identifiant "%s" existe déjà sur l\'agence %s (Id Contact: %s, Raison sociale: %s).',
                        $dto->idContact,
                        $agencyCode,
                        $existing['id_contact'],
                        $existing['raison_sociale'] ?? 'N/A'
                    )
                );
            }
        }

        // ── Préparation des données ──────────────────────────
        $data = $dto->toArray();

        // Retirer les clés dont la valeur est null pour éviter
        // d'écraser les defaults MySQL éventuels
        $data = array_filter($data, fn($value) => $value !== null);

        // ── Insertion DBAL ───────────────────────────────────
        try {
            $this->connection->insert($tableName, $data);
            $newId = (int) $this->connection->lastInsertId();

            $this->logger->info('[ContactService] Client créé', [
                'agency'   => $agencyCode,
                'table'    => $tableName,
                'id'       => $newId,
                'id_contact' => $dto->idContact,
                'raison_sociale' => $dto->raisonSociale,
            ]);

            return $newId;

        } catch (UniqueConstraintViolationException $e) {
            $this->logger->error('[ContactService] Doublon détecté à l\'insertion', [
                'agency' => $agencyCode,
                'id_contact' => $dto->idContact,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                sprintf('Doublon détecté lors de l\'insertion dans %s.', $tableName),
                0,
                $e
            );
        } catch (\Exception $e) {
            $this->logger->error('[ContactService] Erreur insertion client', [
                'agency' => $agencyCode,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                sprintf('Erreur lors de la création du client sur l\'agence %s : %s', $agencyCode, $e->getMessage()),
                0,
                $e
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // Recherche
    // ─────────────────────────────────────────────────────────

    /**
     * Recherche un client par son id_contact sur une agence.
     * Utilisé pour la vérification d'unicité avant insertion.
     * 
     * @return array|null Les données du client ou null si non trouvé
     */
    public function findByIdContact(string $agencyCode, string $idContact): ?array
    {
        $tableName = $this->getContactTableName($agencyCode);

        $result = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id_contact = :id_contact LIMIT 1', $tableName),
            ['id_contact' => $idContact]
        );

        return $result ?: null;
    }

    /**
     * Recherche un client par son ID (PK) sur une agence.
     * 
     * @return array|null Les données du client ou null si non trouvé
     */
    public function findById(string $agencyCode, int $id): ?array
    {
        $tableName = $this->getContactTableName($agencyCode);

        $result = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = :id', $tableName),
            ['id' => $id]
        );

        return $result ?: null;
    }

    /**
     * Recherche un client par raison_sociale (recherche exacte).
     * Utile pour détecter les doublons potentiels avant création.
     * 
     * @return array<int, array> Liste des clients correspondants
     */
    public function findByRaisonSociale(string $agencyCode, string $raisonSociale): array
    {
        $tableName = $this->getContactTableName($agencyCode);

        return $this->connection->fetchAllAssociative(
            sprintf('SELECT id, raison_sociale, id_contact, villep FROM %s WHERE raison_sociale = :rs', $tableName),
            ['rs' => $raisonSociale]
        );
    }

    // ─────────────────────────────────────────────────────────
    // Mise à jour (préparé pour Phase 2 édition)
    // ─────────────────────────────────────────────────────────

    /**
     * Met à jour un client existant.
     * 
     * @param string     $agencyCode Code agence
     * @param int        $id         PK du client
     * @param ContactDTO $dto        DTO avec les nouvelles valeurs
     * 
     * @return bool True si la mise à jour a affecté au moins une ligne
     */
    public function updateContact(string $agencyCode, int $id, ContactDTO $dto): bool
    {
        $tableName = $this->getContactTableName($agencyCode);

        // Vérifier unicité id_contact si modifié
        if (!empty($dto->idContact)) {
            $existing = $this->findByIdContact($agencyCode, $dto->idContact);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                throw new \RuntimeException(
                    sprintf(
                        'L\'identifiant "%s" est déjà utilisé par un autre client (ID: %d).',
                        $dto->idContact,
                        $existing['id']
                    )
                );
            }
        }

        $data = $dto->toArray();

        try {
            $affectedRows = $this->connection->update($tableName, $data, ['id' => $id]);

            $this->logger->info('[ContactService] Client mis à jour', [
                'agency' => $agencyCode,
                'id'     => $id,
                'affected_rows' => $affectedRows,
            ]);

            return $affectedRows > 0;

        } catch (\Exception $e) {
            $this->logger->error('[ContactService] Erreur mise à jour client', [
                'agency' => $agencyCode,
                'id'     => $id,
                'error'  => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                sprintf('Erreur lors de la mise à jour du client #%d sur %s.', $id, $agencyCode),
                0,
                $e
            );
        }
    }
}
