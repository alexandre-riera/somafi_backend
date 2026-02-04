<?php

namespace App\Service;

use App\Entity\ContratCadre;
use App\Repository\ContratCadreRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service pour la gestion des portails Contrat Cadre
 * 
 * Permet de rechercher les sites d'un client CC sur les 13 agences SOMAFI
 * et de récupérer leurs équipements.
 */
class ContratCadreService
{
    private const AGENCY_CODES = ['S10', 'S40', 'S50', 'S60', 'S70', 'S80', 'S100', 'S120', 'S130', 'S140', 'S150', 'S160', 'S170'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContratCadreRepository $contratCadreRepository,
        private readonly LoggerInterface $logger
    ) {
    }

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
     * 
     * @return array<int, array{
     *     id: int,
     *     id_contact: string,
     *     raison_sociale: string,
     *     adressep_1: ?string,
     *     adressep_2: ?string,
     *     cpostalp: ?string,
     *     villep: ?string,
     *     telephone: ?string,
     *     email: ?string,
     *     contact_site: ?string,
     *     agency_code: string,
     *     agency_name: string
     * }>
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
                        :agency_code as agency_code,
                        a.nom as agency_name
                    FROM {$tableName} c
                    LEFT JOIN agencies a ON a.code = :agency_code
                    WHERE c.raison_sociale LIKE :pattern
                    ORDER BY c.raison_sociale ASC
                ";

                $stmt = $this->connection->prepare($sql);
                $result = $stmt->executeQuery([
                    'agency_code' => $agencyCode,
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
     * 
     * @return array<string, int>
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
                    :agency_code as agency_code,
                    a.nom as agency_name
                FROM {$tableName} c
                LEFT JOIN agencies a ON a.code = :agency_code
                WHERE c.id_contact = :id_contact
                  AND c.raison_sociale LIKE :pattern
                LIMIT 1
            ";

            $stmt = $this->connection->prepare($sql);
            $result = $stmt->executeQuery([
                'agency_code' => $agencyCode,
                'id_contact' => $idContact,
                'pattern' => $pattern
            ]);

            return $result->fetchAssociative() ?: null;

        } catch (\Exception $e) {
            $this->logger->error("Erreur getSite CC: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère les équipements d'un site pour un contrat cadre
     * 
     * @return array{
     *     equipments: array,
     *     years: array<string>,
     *     visits: array<string>,
     *     stats: array{total: int, au_contrat: int, hors_contrat: int}
     * }
     */
    public function getEquipmentsForSite(
        string $agencyCode, 
        string $idContact, 
        ?string $annee = null, 
        ?string $visite = null
    ): array {
        $agencyCode = strtoupper($agencyCode);
        
        if (!in_array($agencyCode, self::AGENCY_CODES)) {
            return ['equipments' => [], 'years' => [], 'visits' => [], 'stats' => ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0]];
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
        
        rsort($years); // Années décroissantes
        sort($visits); // Visites croissantes

        // Défaut : année la plus récente, dernière visite
        if ($annee === null && !empty($years)) {
            $annee = $years[0];
        }
        if ($visite === null && !empty($visits)) {
            // Prendre la dernière visite de l'année sélectionnée
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

    /**
     * Récupère les fichiers CC disponibles pour un contact
     */
    public function getFilesForContact(string $agencyCode, string $idContact): array
    {
        try {
            // D'abord trouver le contact_cc correspondant
            $sql = "
                SELECT f.id, f.name, f.path
                FROM files_cc f
                INNER JOIN contacts_cc cc ON f.id_contact_cc_id = cc.id
                WHERE cc.id_contact = :id_contact
                  AND cc.code_agence = :agency_code
                ORDER BY f.name ASC
            ";

            $result = $this->connection->executeQuery($sql, [
                'id_contact' => $idContact,
                'agency_code' => $agencyCode
            ]);

            return $result->fetchAllAssociative();

        } catch (\Exception $e) {
            $this->logger->warning("Erreur getFilesForContact: " . $e->getMessage());
            return [];
        }
    }

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
