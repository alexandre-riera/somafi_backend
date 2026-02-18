<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\AgencyRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service métier pour la gestion des contrats d'entretien.
 * 
 * Gère la résolution dynamique des tables par agence (contrat_sXX, contact_sXX)
 * et fournit les requêtes DBAL pour le listing, le détail et les statistiques.
 */
class ContratEntretienService
{
    public const AGENCY_CODES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80',
        'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    /** Statuts possibles d'un contrat */
    public const STATUTS = ['actif', 'resilie', 'suspendu', 'en_attente'];

    public function __construct(
        private readonly Connection $connection,
        private readonly AgencyRepository $agencyRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    // =========================================================================
    //  Résolution dynamique
    // =========================================================================

    /**
     * Vérifie qu'un code agence est valide.
     */
    public function isValidAgencyCode(string $agencyCode): bool
    {
        return in_array(strtoupper($agencyCode), self::AGENCY_CODES, true);
    }

    /**
     * Normalise le code agence (ex: "s100" → "S100").
     */
    public function normalizeAgencyCode(string $agencyCode): string
    {
        return strtoupper($agencyCode);
    }

    /**
     * Retourne le nom de la table contrat pour une agence.
     * Ex : "S100" → "contrat_s100"
     */
    public function getContratTableName(string $agencyCode): string
    {
        return 'contrat_' . strtolower($agencyCode);
    }

    /**
     * Retourne le nom de la table contact pour une agence.
     * Ex : "S100" → "contact_s100"
     */
    public function getContactTableName(string $agencyCode): string
    {
        return 'contact_' . strtolower($agencyCode);
    }

    /**
     * Retourne le nom de la table avenant pour une agence.
     * Ex : "S100" → "contrat_avenant_s100"
     */
    public function getAvenantTableName(string $agencyCode): string
    {
        return 'contrat_avenant_' . strtolower($agencyCode);
    }

    /**
     * Retourne le nom de la table équipement pour une agence.
     * Ex : "S100" → "equipement_s100"
     */
    public function getEquipementTableName(string $agencyCode): string
    {
        return 'equipement_' . strtolower($agencyCode);
    }

    /**
     * Résout le FQCN de l'entité Contrat pour une agence.
     * Ex : "S100" → "App\Entity\ContratS100"
     */
    public function getContratEntityClass(string $agencyCode): string
    {
        return 'App\\Entity\\Contrat' . $this->normalizeAgencyCode($agencyCode);
    }

    /**
     * Résout le FQCN de l'entité ContratAvenant pour une agence.
     */
    public function getAvenantEntityClass(string $agencyCode): string
    {
        return 'App\\Entity\\ContratAvenant' . $this->normalizeAgencyCode($agencyCode);
    }

    /**
     * Retourne les infos de l'agence (nom, code, etc.).
     */
    public function getAgencyInfo(string $agencyCode): ?array
    {
        $code = $this->normalizeAgencyCode($agencyCode);

        return $this->connection->fetchAssociative(
            'SELECT id, code, nom, telephone, email FROM agencies WHERE code = :code',
            ['code' => $code]
        ) ?: null;
    }

    /**
     * Retourne la liste des agences accessibles par un utilisateur.
     */
    public function getAccessibleAgencies(User $user): array
    {
        if (in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return self::AGENCY_CODES;
        }

        return $user->getAgencies() ?? [];
    }

    // =========================================================================
    //  Listing des contrats
    // =========================================================================

    /**
     * Récupère les contrats d'entretien d'une agence avec jointure client.
     *
     * @param string      $agencyCode  Code agence (ex: "S100")
     * @param array       $filters     Filtres optionnels : statut, search, sort, order
     * @param int         $page        Page courante (1-based)
     * @param int         $perPage     Nombre de résultats par page
     *
     * @return array{contrats: array, total: int, pages: int}
     */
    public function getContratsPaginated(
        string $agencyCode,
        array $filters = [],
        int $page = 1,
        int $perPage = 20,
    ): array {
        $contratTable  = $this->getContratTableName($agencyCode);
        $contactTable  = $this->getContactTableName($agencyCode);

        // --- Construction de la clause WHERE ---
        $where  = [];
        $params = [];

        // Filtre par statut
        if (!empty($filters['statut']) && in_array($filters['statut'], self::STATUTS, true)) {
            $where[]           = 'c.statut = :statut';
            $params['statut']  = $filters['statut'];
        }

        // Recherche textuelle (client, numéro contrat)
        if (!empty($filters['search'])) {
            $where[]           = '(co.nom LIKE :search OR co.raison_sociale LIKE :search OR CAST(c.numero_contrat AS CHAR) LIKE :search)';
            $params['search']  = '%' . $filters['search'] . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // --- Tri ---
        $allowedSorts = ['numero_contrat', 'date_signature', 'statut', 'nombre_visite', 'montant_annuel_ht', 'date_fin_contrat', 'raison_sociale'];
        $sort  = in_array($filters['sort'] ?? '', $allowedSorts, true) ? $filters['sort'] : 'c.id';
        $order = strtoupper($filters['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        // Préfixer le tri si besoin
        if ($sort === 'raison_sociale') {
            $sort = 'co.raison_sociale';
        } elseif (!str_contains($sort, '.')) {
            $sort = 'c.' . $sort;
        }

        // --- Comptage total ---
        $countSql = "
            SELECT COUNT(*) 
            FROM {$contratTable} c
            LEFT JOIN {$contactTable} co ON c.contact_id = co.id
            {$whereClause}
        ";
        $total = (int) $this->connection->fetchOne($countSql, $params);

        // --- Requête paginée ---
        $offset = ($page - 1) * $perPage;
        $sql = "
            SELECT 
                c.id,
                c.numero_contrat,
                c.date_signature,
                c.duree,
                c.is_tacite_reconduction,
                c.nombre_visite,
                c.nombre_equipement,
                c.statut,
                c.date_resiliation,
                c.montant_annuel_ht,
                c.date_debut_contrat,
                c.date_fin_contrat,
                c.mode_revalorisation,
                c.contrat_pdf_path,
                c.notes,
                c.created_at,
                c.updated_at,
                c.id_contact,
                c.contact_id,
                co.nom AS client_nom,
                co.raison_sociale AS client_raison_sociale,
                co.villep AS client_ville,
                co.cpostalp AS client_cp,
                co.telephone AS client_telephone
            FROM {$contratTable} c
            LEFT JOIN {$contactTable} co ON c.contact_id = co.id
            {$whereClause}
            ORDER BY {$sort} {$order}
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $contrats = $this->connection->fetchAllAssociative($sql, $params);

        return [
            'contrats' => $contrats,
            'total'    => $total,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    // =========================================================================
    //  Détail d'un contrat
    // =========================================================================

    /**
     * Récupère un contrat par son ID avec les infos client jointes.
     */
    public function getContratById(string $agencyCode, int $id): ?array
    {
        $contratTable = $this->getContratTableName($agencyCode);
        $contactTable = $this->getContactTableName($agencyCode);

        $sql = "
            SELECT 
                c.*,
                co.nom AS client_nom,
                co.prenom AS client_prenom,
                co.raison_sociale AS client_raison_sociale,
                co.adressep_1 AS client_adresse1,
                co.adressep_2 AS client_adresse2,
                co.cpostalp AS client_cp,
                co.villep AS client_ville,
                co.telephone AS client_telephone,
                co.email AS client_email,
                co.contact_site AS client_contact_site,
                co.id_contact AS client_id_contact,
                co.id_societe AS client_id_societe
            FROM {$contratTable} c
            LEFT JOIN {$contactTable} co ON c.contact_id = co.id
            WHERE c.id = :id
        ";

        return $this->connection->fetchAssociative($sql, ['id' => $id]) ?: null;
    }

    /**
     * Récupère les avenants d'un contrat.
     */
    public function getAvenantsByContratId(string $agencyCode, int $contratId): array
    {
        $table = $this->getAvenantTableName($agencyCode);

        return $this->connection->fetchAllAssociative(
            "SELECT * FROM {$table} WHERE contrat_id = :contratId ORDER BY date_avenant DESC",
            ['contratId' => $contratId]
        );
    }

    // =========================================================================
    //  Statistiques
    // =========================================================================

    /**
     * Compte les équipements liés à un contrat (via id_contact).
     *
     * @return array{au_contrat: int, hors_contrat: int, total: int}
     */
    public function countEquipementsForContrat(string $agencyCode, string $idContact): array
    {
        $table = $this->getEquipementTableName($agencyCode);

        $sql = "
            SELECT 
                SUM(CASE WHEN is_hors_contrat = 0 OR is_hors_contrat IS NULL THEN 1 ELSE 0 END) AS au_contrat,
                SUM(CASE WHEN is_hors_contrat = 1 THEN 1 ELSE 0 END) AS hors_contrat,
                COUNT(*) AS total
            FROM {$table}
            WHERE id_contact = :idContact
        ";

        $result = $this->connection->fetchAssociative($sql, ['idContact' => $idContact]);

        return [
            'au_contrat'    => (int) ($result['au_contrat'] ?? 0),
            'hors_contrat'  => (int) ($result['hors_contrat'] ?? 0),
            'total'         => (int) ($result['total'] ?? 0),
        ];
    }

    /**
     * Statistiques globales d'une agence (nombre de contrats par statut).
     */
    public function getAgencyStats(string $agencyCode): array
    {
        $table = $this->getContratTableName($agencyCode);

        $sql = "
            SELECT 
                statut,
                COUNT(*) AS nb
            FROM {$table}
            GROUP BY statut
        ";

        $rows  = $this->connection->fetchAllAssociative($sql);
        $stats = ['total' => 0];

        foreach ($rows as $row) {
            $stats[$row['statut']] = (int) $row['nb'];
            $stats['total']       += (int) $row['nb'];
        }

        return $stats;
    }

    // =========================================================================
    //  Désactivation (soft-delete)
    // =========================================================================

    /**
     * Désactive (résilie) un contrat.
     */
    public function deactivateContrat(string $agencyCode, int $id, string $motif = ''): bool
    {
        $table = $this->getContratTableName($agencyCode);

        $affected = $this->connection->executeStatement(
            "UPDATE {$table} SET statut = 'resilie', date_resiliation = :dateRes, motif_resiliation = :motif, updated_at = NOW() WHERE id = :id",
            [
                'dateRes' => (new \DateTimeImmutable())->format('Y-m-d'),
                'motif'   => $motif,
                'id'      => $id,
            ]
        );

        return $affected > 0;
    }

    /**
     * Supprime définitivement un contrat et ses avenants (hard delete).
     */
    public function deleteContrat(string $agencyCode, int $id): bool
    {
        $contratTable  = $this->getContratTableName($agencyCode);
        $avenantTable  = $this->getAvenantTableName($agencyCode);

        $this->connection->beginTransaction();

        try {
            // Supprimer les avenants d'abord (FK)
            $this->connection->executeStatement(
                "DELETE FROM {$avenantTable} WHERE contrat_id = :id",
                ['id' => $id]
            );

            // Supprimer le contrat
            $affected = $this->connection->executeStatement(
                "DELETE FROM {$contratTable} WHERE id = :id",
                ['id' => $id]
            );

            $this->connection->commit();

            return $affected > 0;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('Erreur suppression contrat', [
                'agency'    => $agencyCode,
                'contratId' => $id,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Récupère une agence par son code.
     */
    public function getAgencyByCode(string $agencyCode): ?array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM agencies WHERE code = :code',
            ['code' => strtoupper($agencyCode)]
        );
    }
}
