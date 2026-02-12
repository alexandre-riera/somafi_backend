<?php

namespace App\Service;

use App\Entity\ContratCadre;
use App\Entity\User;
use App\Repository\ContratCadreRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Service pour la gestion des portails Contrat Cadre
 * 
 * Permet de rechercher les sites d'un client CC sur les 13 agences SOMAFI
 * et de récupérer leurs équipements, gérer les fichiers uploadés.
 */
class ContratCadreService
{
    private const AGENCY_CODES = ['S10', 'S40', 'S50', 'S60', 'S70', 'S80', 'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContratCadreRepository $contratCadreRepository,
        private readonly LoggerInterface $logger,
        private readonly SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    // ========================================================================
    //  CONTRAT CADRE - Lecture
    // ========================================================================

    /**
     * Récupère un contrat cadre par son slug
     */
    public function getContratCadreBySlug(string $slug): ?ContratCadre
    {
        return $this->contratCadreRepository->findOneBy([
            'slug' => $slug,
            'isActive' => true
        ]);
    }

    /**
     * Recherche tous les sites d'un contrat cadre sur les 13 agences
     */
    public function findAllSitesForContratCadre(ContratCadre $contratCadre): array
    {
        $pattern = $contratCadre->getSearchPattern();
        $allSites = [];

        foreach (self::AGENCY_CODES as $agencyCode) {
            $tableName = 'contact_' . strtolower($agencyCode);

            try {
                $sql = "
                    SELECT 
                        c.id,
                        c.id_contact,
                        c.raison_sociale,
                        c.adressep_1,
                        c.adressep_2,
                        c.cpostalp,
                        c.villep,
                        c.telephone,
                        c.email,
                        c.contact_site,
                        '{$agencyCode}' as agency_code,
                        a.nom as agency_name
                    FROM {$tableName} c
                    LEFT JOIN agencies a ON a.code = '{$agencyCode}'
                    WHERE c.raison_sociale LIKE :pattern
                    ORDER BY c.raison_sociale ASC
                ";

                $result = $this->connection->executeQuery($sql, [
                    'pattern' => $pattern
                ]);

                $sites = $result->fetchAllAssociative();

                foreach ($sites as $site) {
                    $allSites[] = $site;
                }
            } catch (\Exception $e) {
                $this->logger->warning("Erreur recherche CC sur {$tableName}: " . $e->getMessage());
            }
        }

        // Tri par ville puis raison sociale
        usort($allSites, function ($a, $b) {
            $villeCompare = strcasecmp($a['villep'] ?? '', $b['villep'] ?? '');
            if ($villeCompare !== 0) {
                return $villeCompare;
            }
            return strcasecmp($a['raison_sociale'] ?? '', $b['raison_sociale'] ?? '');
        });

        $this->logger->info("ContratCadre {$contratCadre->getSlug()}: " . count($allSites) . " sites trouvés");

        return $allSites;
    }

    /**
     * Compte le nombre de sites par agence
     */
    public function countSitesByAgency(array $sites): array
    {
        $counts = [];
        foreach ($sites as $site) {
            $code = $site['agency_code'];
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Récupère un site spécifique d'un contrat cadre
     */
    public function getSite(ContratCadre $contratCadre, string $agencyCode, string $idContact): ?array
    {
        $agencyCode = strtoupper($agencyCode);

        if (!in_array($agencyCode, self::AGENCY_CODES)) {
            return null;
        }

        $tableName = 'contact_' . strtolower($agencyCode);
        $pattern = $contratCadre->getSearchPattern();

        try {
            $sql = "
                SELECT 
                    c.id,
                    c.id_contact,
                    c.raison_sociale,
                    c.adressep_1,
                    c.adressep_2,
                    c.cpostalp,
                    c.villep,
                    c.telephone,
                    c.email,
                    c.contact_site,
                    '{$agencyCode}' as agency_code,
                    a.nom as agency_name
                FROM {$tableName} c
                LEFT JOIN agencies a ON a.code = '{$agencyCode}'
                WHERE c.id_contact = :id_contact
                AND c.raison_sociale LIKE :pattern
                LIMIT 1
            ";

            $result = $this->connection->executeQuery($sql, [
                'id_contact' => $idContact,
                'pattern' => $pattern
            ]);

            return $result->fetchAssociative() ?: null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur getSite CC: " . $e->getMessage());
            return null;
        }
    }

    // ========================================================================
    //  ÉQUIPEMENTS
    // ========================================================================

    /**
     * Récupère les équipements d'un site pour un contrat cadre
     * Avec pagination côté serveur
     */
    public function getEquipmentsForSite(
        string $agencyCode,
        string $idContact,
        ?string $annee = null,
        ?string $visite = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $agencyCode = strtoupper($agencyCode);

        $emptyResult = [
            'equipments' => [],
            'years' => [],
            'visits' => [],
            'current_year' => null,
            'current_visit' => null,
            'stats' => ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0],
            'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0, 'offset' => 0]
        ];

        if (!in_array($agencyCode, self::AGENCY_CODES)) {
            return $emptyResult;
        }

        $tableName = 'equipement_' . strtolower($agencyCode);

        // 1. Récupérer les années et visites disponibles
        $metaSql = "
            SELECT DISTINCT annee, visite 
            FROM {$tableName} 
            WHERE id_contact = :id_contact 
            ORDER BY annee DESC, visite ASC
        ";

        try {
            $metaResult = $this->connection->executeQuery($metaSql, ['id_contact' => $idContact]);
            $metaData = $metaResult->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->logger->error("Erreur getMeta équipements: " . $e->getMessage());
            $metaData = [];
        }

        $years = array_unique(array_column($metaData, 'annee'));
        $visits = array_unique(array_column($metaData, 'visite'));

        rsort($years);
        sort($visits);

        // Défaut : année la plus récente, dernière visite
        if ($annee === null && !empty($years)) {
            $annee = $years[0];
        }
        if ($visite === null && !empty($visits)) {
            $visitesAnnee = array_filter($metaData, fn($m) => $m['annee'] === $annee);
            $visitesDisponibles = array_column($visitesAnnee, 'visite');
            $visite = !empty($visitesDisponibles) ? max($visitesDisponibles) : ($visits[0] ?? null);
        }

        // 2. Récupérer les stats (indépendantes de la pagination)
        $stats = ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0];
        $equipments = [];
        $pagination = ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0, 'offset' => 0];

        if ($annee && $visite) {
            // Compteur global (hors pagination)
            $countSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_hors_contrat = 0 THEN 1 ELSE 0 END) as au_contrat,
                    SUM(CASE WHEN is_hors_contrat = 1 THEN 1 ELSE 0 END) as hors_contrat
                FROM {$tableName}
                WHERE id_contact = :id_contact
                  AND annee = :annee
                  AND visite = :visite
                  AND is_archive = 0
            ";

            try {
                $countResult = $this->connection->executeQuery($countSql, [
                    'id_contact' => $idContact,
                    'annee' => $annee,
                    'visite' => $visite
                ]);
                $countData = $countResult->fetchAssociative();
                $stats = [
                    'total' => (int) ($countData['total'] ?? 0),
                    'au_contrat' => (int) ($countData['au_contrat'] ?? 0),
                    'hors_contrat' => (int) ($countData['hors_contrat'] ?? 0),
                ];
            } catch (\Exception $e) {
                $this->logger->error("Erreur countEquipments CC: " . $e->getMessage());
            }

            // Pagination
            $totalPages = $stats['total'] > 0 ? (int) ceil($stats['total'] / $perPage) : 0;
            $page = max(1, min($page, max(1, $totalPages)));
            $offset = ($page - 1) * $perPage;

            $pagination = [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $stats['total'],
                'total_pages' => $totalPages,
                'offset' => $offset,
            ];

            // 3. Récupérer les équipements paginés
            $equipSql = "
                SELECT 
                    id,
                    numero_equipement,
                    libelle_equipement,
                    visite,
                    annee,
                    date_derniere_visite,
                    repere_site_client,
                    mise_en_service,
                    numero_serie,
                    marque,
                    mode_fonctionnement,
                    hauteur,
                    largeur,
                    longueur,
                    etat_equipement,
                    statut_equipement,
                    anomalies,
                    observations,
                    trigramme_tech,
                    is_hors_contrat,
                    is_archive
                FROM {$tableName}
                WHERE id_contact = :id_contact
                  AND annee = :annee
                  AND visite = :visite
                  AND is_archive = 0
                ORDER BY is_hors_contrat ASC, numero_equipement ASC
                LIMIT {$perPage} OFFSET {$offset}
            ";

            try {
                $result = $this->connection->executeQuery($equipSql, [
                    'id_contact' => $idContact,
                    'annee' => $annee,
                    'visite' => $visite
                ]);
                $equipments = $result->fetchAllAssociative();
            } catch (\Exception $e) {
                $this->logger->error("Erreur getEquipments CC: " . $e->getMessage());
            }
        }

        return [
            'equipments' => $equipments,
            'years' => $years,
            'visits' => $visits,
            'current_year' => $annee,
            'current_visit' => $visite,
            'stats' => $stats,
            'pagination' => $pagination
        ];
    }

    // ========================================================================
    //  FICHIERS CC - Upload / Download / Delete
    // ========================================================================

    /**
     * Récupère les fichiers CC disponibles pour un contact
     */
    public function getFilesForContact(string $agencyCode, string $idContact): array
    {
        try {
            $sql = "
                SELECT f.id, f.name, f.path, f.original_name, f.file_size, f.uploaded_at
                FROM files_cc f
                INNER JOIN contacts_cc cc ON f.id_contact_cc_id = cc.id
                WHERE cc.id_contact = :id_contact
                  AND cc.code_agence = :agency_code
                ORDER BY f.uploaded_at DESC, f.name ASC
            ";

            $result = $this->connection->executeQuery($sql, [
                'id_contact' => $idContact,
                'agency_code' => strtoupper($agencyCode)
            ]);

            return $result->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->logger->warning("Erreur getFilesForContact: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère un fichier CC par son ID
     */
    public function getFileById(int $fileId): ?array
    {
        try {
            $sql = "
                SELECT f.*, cc.id_contact, cc.code_agence, cc.contrat_cadre_id
                FROM files_cc f
                INNER JOIN contacts_cc cc ON f.id_contact_cc_id = cc.id
                WHERE f.id = :file_id
            ";

            $result = $this->connection->executeQuery($sql, ['file_id' => $fileId]);
            return $result->fetchAssociative() ?: null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur getFileById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload un fichier PDF pour un contact CC
     * 
     * @return array{success: bool, message: string, fileId?: int}
     */
    public function uploadFile(
        UploadedFile $file,
        ContratCadre $contratCadre,
        string $agencyCode,
        string $idContact,
        int $uploadedById
    ): array {
        // Validation MIME
        $allowedMimes = ['application/pdf'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return ['success' => false, 'message' => 'Seuls les fichiers PDF sont autorisés.'];
        }

        // Validation taille (20 Mo max)
        $maxSize = 20 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return ['success' => false, 'message' => 'Le fichier ne doit pas dépasser 20 Mo.'];
        }

        $agencyCode = strtoupper($agencyCode);
        $slug = $contratCadre->getSlug();

        // Résoudre ou créer le contact_cc
        $contactCcId = $this->getOrCreateContactCc($contratCadre, $agencyCode, $idContact);
        if (!$contactCcId) {
            return ['success' => false, 'message' => 'Impossible de résoudre le contact CC.'];
        }

        // Générer un nom de fichier sécurisé
        $originalName = $file->getClientOriginalName();
        $safeName = $this->slugger->slug(pathinfo($originalName, PATHINFO_FILENAME));
        $timestamp = time();
        $hash = substr(md5(uniqid()), 0, 8);
        $fileName = $safeName . '-' . $timestamp . '-' . $hash . '.pdf';

        // Chemin relatif : {slug}/{agencyCode}/{idContact}/
        $relativePath = $slug . '/' . $agencyCode . '/' . $idContact . '/' . $fileName;
        $absoluteDir = $this->projectDir . '/public/uploads/cc/' . $slug . '/' . $agencyCode . '/' . $idContact;

        // Créer le répertoire si nécessaire
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        // Juste AVANT $file->move()
        $fileSize = $file->getSize();

        // Déplacer le fichier
        try {
            $file->move($absoluteDir, $fileName);
        } catch (\Exception $e) {
            $this->logger->error("Erreur upload fichier CC: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur lors de l\'upload du fichier.'];
        }

        // Nom affiché (sans extension)
        $displayName = pathinfo($originalName, PATHINFO_FILENAME);

        // INSERT dans files_cc
        try {
            $this->connection->insert('files_cc', [
                'name' => $displayName,
                'path' => $relativePath,
                'original_name' => $originalName,
                'file_size' => $fileSize,
                'uploaded_by_id' => $uploadedById,
                'uploaded_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'contrat_cadre_id' => $contratCadre->getId(),
                'id_contact_cc_id' => $contactCcId,
            ]);

            $fileId = (int) $this->connection->lastInsertId();

            $this->logger->info("Fichier CC uploadé: {$relativePath} (id={$fileId})");

            return ['success' => true, 'message' => 'Fichier uploadé avec succès.', 'fileId' => $fileId];
        } catch (\Exception $e) {
            $this->logger->error("Erreur INSERT files_cc: " . $e->getMessage());
            // Nettoyer le fichier uploadé en cas d'erreur BDD
            @unlink($absoluteDir . '/' . $fileName);
            return ['success' => false, 'message' => 'Erreur lors de l\'enregistrement en base de données.'];
        }
    }

    /**
     * Supprime un fichier CC (fichier physique + entrée BDD)
     */
    public function deleteFile(int $fileId): bool
    {
        $file = $this->getFileById($fileId);
        if (!$file) {
            return false;
        }

        // Supprimer le fichier physique
        $absolutePath = $this->projectDir . '/public/uploads/cc/' . $file['path'];
        if (file_exists($absolutePath)) {
            @unlink($absolutePath);
        }

        // Supprimer l'entrée BDD
        try {
            $this->connection->delete('files_cc', ['id' => $fileId]);
            $this->logger->info("Fichier CC supprimé: id={$fileId}, path={$file['path']}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Erreur DELETE files_cc: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère ou crée un contact_cc pour le lien fichier <-> contact
     */
    public function getOrCreateContactCc(ContratCadre $contratCadre, string $agencyCode, string $idContact): ?int
    {
        $agencyCode = strtoupper($agencyCode);

        // Chercher si le contact_cc existe déjà
        try {
            $sql = "SELECT id FROM contacts_cc WHERE id_contact = :id_contact AND code_agence = :agency_code LIMIT 1";
            $result = $this->connection->executeQuery($sql, [
                'id_contact' => $idContact,
                'agency_code' => $agencyCode
            ]);

            $existing = $result->fetchAssociative();
            if ($existing) {
                return (int) $existing['id'];
            }
        } catch (\Exception $e) {
            $this->logger->error("Erreur SELECT contacts_cc: " . $e->getMessage());
            return null;
        }

        // Le contact n'existe pas, on le crée
        // Récupérer la raison sociale depuis la table contact_sXX
        $raisonSociale = 'Inconnu';
        if (in_array($agencyCode, self::AGENCY_CODES)) {
            $tableName = 'contact_' . strtolower($agencyCode);
            try {
                $sql = "SELECT raison_sociale FROM {$tableName} WHERE id_contact = :id_contact LIMIT 1";
                $result = $this->connection->executeQuery($sql, ['id_contact' => $idContact]);
                $contact = $result->fetchAssociative();
                if ($contact) {
                    $raisonSociale = $contact['raison_sociale'];
                }
            } catch (\Exception $e) {
                $this->logger->warning("Erreur récup raison_sociale: " . $e->getMessage());
            }
        }

        try {
            $this->connection->insert('contacts_cc', [
                'id_contact' => $idContact,
                'code_agence' => $agencyCode,
                'raison_sociale_contact' => $raisonSociale,
                'contrat_cadre_id' => $contratCadre->getId(),
            ]);

            $newId = (int) $this->connection->lastInsertId();
            $this->logger->info("Contact CC créé: id={$newId}, id_contact={$idContact}, agence={$agencyCode}");
            return $newId;
        } catch (\Exception $e) {
            $this->logger->error("Erreur INSERT contacts_cc: " . $e->getMessage());
            return null;
        }
    }

    // ========================================================================
    //  PERMISSIONS
    // ========================================================================

    /**
     * Vérifie si un utilisateur a accès à un contrat cadre
     */
    public function userHasAccessToContratCadre($user, ContratCadre $contratCadre): bool
    {
        // Admin global : accès total
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Admin CC global
        if (in_array('ROLE_CC_ADMIN', $user->getRoles())) {
            return true;
        }

        // Vérifier le rôle spécifique au CC
        $adminRole = $contratCadre->getAdminRole();
        $userRole = $contratCadre->getUserRole();

        return in_array($adminRole, $user->getRoles()) || in_array($userRole, $user->getRoles());
    }

    /**
     * Vérifie si un utilisateur est admin d'un contrat cadre
     * (peut uploader/supprimer des fichiers)
     */
    public function isUserCcAdmin($user, ContratCadre $contratCadre): bool
    {
        if (!$user) {
            return false;
        }

        $roles = $user->getRoles();

        // Admin global
        if (in_array('ROLE_ADMIN', $roles)) {
            return true;
        }

        // Admin CC global
        if (in_array('ROLE_CC_ADMIN', $roles)) {
            return true;
        }

        // Admin spécifique au CC (ex: MONDIAL-RELAY_ADMIN)
        $adminRole = $contratCadre->getAdminRole();
        return in_array($adminRole, $roles);
    }
}
