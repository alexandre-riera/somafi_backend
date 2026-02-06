<?php

namespace App\Service;

use App\Entity\ContratCadre;
use App\Repository\ContratCadreRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Service pour la gestion des portails Contrat Cadre
 * 
 * Permet de rechercher les sites d'un client CC sur les 13 agences SOMAFI,
 * de récupérer leurs équipements, et de gérer les fichiers CR clients.
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

    // =========================================================================
    // Contrat Cadre & Sites
    // =========================================================================

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
                // $agencyCode vient de AGENCY_CODES (constante) → safe pour injection littérale
                // Les named params dans le SELECT ne fonctionnent pas avec prepare()+executeQuery()
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
        usort($allSites, function($a, $b) {
            $villeCompare = strcasecmp($a['villep'] ?? '', $b['villep'] ?? '');
            if ($villeCompare !== 0) {
                return $villeCompare;
            }
            return strcasecmp($a['raison_sociale'] ?? '', $b['raison_sociale'] ?? '');
        });

        $this->logger->info("ContratCadre {$contratCadre->getSlug()}: {$this->countSites($allSites)} sites trouvés");

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

    // =========================================================================
    // Équipements
    // =========================================================================

    /**
     * Récupère les équipements d'un site pour un contrat cadre
     */
    public function getEquipmentsForSite(
        string $agencyCode, 
        string $idContact, 
        ?string $annee = null, 
        ?string $visite = null
    ): array {
        $agencyCode = strtoupper($agencyCode);
        
        if (!in_array($agencyCode, self::AGENCY_CODES)) {
            return ['equipments' => [], 'years' => [], 'visits' => [], 'current_year' => null, 'current_visit' => null, 'stats' => ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0]];
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

        // 2. Récupérer les équipements
        $equipments = [];
        $stats = ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0];

        if ($annee && $visite) {
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
            ";

            try {
                $result = $this->connection->executeQuery($equipSql, [
                    'id_contact' => $idContact,
                    'annee' => $annee,
                    'visite' => $visite
                ]);
                $equipments = $result->fetchAllAssociative();

                foreach ($equipments as $eq) {
                    $stats['total']++;
                    if ($eq['is_hors_contrat']) {
                        $stats['hors_contrat']++;
                    } else {
                        $stats['au_contrat']++;
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error("Erreur getEquipments: " . $e->getMessage());
            }
        }

        return [
            'equipments' => $equipments,
            'years' => $years,
            'visits' => $visits,
            'current_year' => $annee,
            'current_visit' => $visite,
            'stats' => $stats
        ];
    }

    // =========================================================================
    // PHASE 1C - Gestion fichiers CR clients
    // =========================================================================

    /**
     * Récupère les fichiers CC disponibles pour un contact
     * 
     * Enrichi avec uploaded_at et file_size (Phase 1C)
     */
    public function getFilesForContact(string $agencyCode, string $idContact): array
    {
        try {
            $sql = "
                SELECT 
                    f.id, 
                    f.name, 
                    f.path, 
                    f.original_name,
                    f.file_size,
                    f.uploaded_at
                FROM files_cc f
                INNER JOIN contacts_cc cc ON f.id_contact_cc_id = cc.id
                WHERE cc.id_contact = :id_contact
                  AND cc.code_agence = :agency_code
                ORDER BY f.uploaded_at DESC
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
     * Récupère un fichier par son ID
     */
    public function getFileById(int $fileId): ?array
    {
        try {
            $sql = "SELECT id, name, path, original_name, file_size, uploaded_at FROM files_cc WHERE id = :id";
            $result = $this->connection->executeQuery($sql, ['id' => $fileId]);
            return $result->fetchAssociative() ?: null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur getFileById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload un fichier CR client pour un contact
     * 
     * Workflow :
     * 1. Trouver ou créer l'entrée contacts_cc
     * 2. Générer un nom de fichier unique
     * 3. Déplacer le fichier dans uploads/cc/{slug}/{agencyCode}/{idContact}/
     * 4. Insérer l'entrée dans files_cc
     * 
     * @return array{id: int, name: string, path: string}
     */
    public function uploadFileForContact(
        ContratCadre $contratCadre,
        string $agencyCode,
        string $idContact,
        UploadedFile $uploadedFile,
        $uploadedBy = null,
        ?string $customName = null
    ): array {
        $agencyCode = strtoupper($agencyCode);

        // 1. Trouver ou créer le contact_cc
        $contactCcId = $this->getOrCreateContactCc($contratCadre, $agencyCode, $idContact);

        // 2. Construire le nom de fichier
        $originalName = $uploadedFile->getClientOriginalName();
        $displayName = $customName ?? pathinfo($originalName, PATHINFO_FILENAME);
        
        // Nom unique pour le stockage : slug-timestamp-hash.pdf
        $safeFilename = $this->slugger->slug($displayName)->lower();
        $uniqueName = $safeFilename . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.pdf';

        // 3. Chemin relatif : {slug}/{agencyCode}/{idContact}/
        $relativePath = $contratCadre->getSlug() . '/' . $agencyCode . '/' . $idContact;
        $absoluteDir = $this->projectDir . '/public/uploads/cc/' . $relativePath;

        // Créer le répertoire si nécessaire
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        // Déplacer le fichier
        $uploadedFile->move($absoluteDir, $uniqueName);

        $this->logger->info("Fichier CC uploadé: {$relativePath}/{$uniqueName}");

        // 4. Insérer en BDD
        $fileRelativePath = $relativePath . '/' . $uniqueName;
        
        $this->connection->insert('files_cc', [
            'name' => $displayName,
            'path' => $fileRelativePath,
            'original_name' => $originalName,
            'file_size' => filesize($absoluteDir . '/' . $uniqueName),
            'id_contact_cc_id' => $contactCcId,
            'uploaded_by_id' => $uploadedBy?->getId(),
            'uploaded_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'contrat_cadre_id' => $contratCadre->getId(),
        ]);

        $insertedId = (int) $this->connection->lastInsertId();

        return [
            'id' => $insertedId,
            'name' => $displayName,
            'path' => $fileRelativePath,
        ];
    }

    /**
     * Supprime un fichier CR client (BDD + fichier physique)
     * 
     * @return array Le fichier supprimé (pour le message flash)
     */
    public function deleteFile(int $fileId, string $uploadBasePath): array
    {
        // Récupérer le fichier
        $file = $this->getFileById($fileId);
        if (!$file) {
            throw new \RuntimeException('Fichier non trouvé');
        }

        // Supprimer le fichier physique
        $filePath = rtrim($uploadBasePath, '/') . '/' . $file['path'];
        if (file_exists($filePath)) {
            unlink($filePath);
            $this->logger->info("Fichier physique supprimé: {$filePath}");
        } else {
            $this->logger->warning("Fichier physique introuvable: {$filePath}");
        }

        // Supprimer l'entrée BDD
        $this->connection->delete('files_cc', ['id' => $fileId]);

        $this->logger->info("Fichier CC supprimé: id={$fileId}, name={$file['name']}");

        return $file;
    }

    /**
     * Trouve ou crée une entrée dans contacts_cc pour un contact donné
     * 
     * contacts_cc est une table intermédiaire qui lie les contacts
     * (des tables contact_sXX) aux fichiers CC.
     */
    private function getOrCreateContactCc(
        ContratCadre $contratCadre,
        string $agencyCode,
        string $idContact
    ): int {
        // Chercher si l'entrée existe déjà
        $sql = "
            SELECT id FROM contacts_cc 
            WHERE id_contact = :id_contact 
              AND code_agence = :agency_code 
            LIMIT 1
        ";

        $existing = $this->connection->executeQuery($sql, [
            'id_contact' => $idContact,
            'agency_code' => $agencyCode,
        ])->fetchAssociative();

        if ($existing) {
            return (int) $existing['id'];
        }

        // Récupérer la raison sociale depuis la table contact_sXX
        $tableName = 'contact_' . strtolower($agencyCode);
        $contactSql = "SELECT raison_sociale FROM {$tableName} WHERE id_contact = :id_contact LIMIT 1";
        $contact = $this->connection->executeQuery($contactSql, ['id_contact' => $idContact])->fetchAssociative();
        
        $raisonSociale = $contact['raison_sociale'] ?? 'Inconnu';

        // Créer l'entrée
        $this->connection->insert('contacts_cc', [
            'id_contact' => $idContact,
            'raison_sociale_contact' => $raisonSociale,
            'code_agence' => $agencyCode,
            'contrat_cadre_id' => $contratCadre->getId(),
        ]);

        $this->logger->info("Contact CC créé: {$agencyCode}/{$idContact} ({$raisonSociale})");

        return (int) $this->connection->lastInsertId();
    }

    // =========================================================================
    // Méthodes utilitaires
    // =========================================================================

    /**
     * Compte le nombre total de sites
     */
    private function countSites(array $sites): int
    {
        return count($sites);
    }

    /**
     * Vérifie si un utilisateur a accès à un contrat cadre
     */
    public function userHasAccessToContratCadre($user, ContratCadre $contratCadre): bool
    {
        // Admin global : accès total
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Vérifier le rôle spécifique au CC
        $adminRole = $contratCadre->getAdminRole();
        $userRole = $contratCadre->getUserRole();

        return in_array($adminRole, $user->getRoles()) || in_array($userRole, $user->getRoles());
    }
}