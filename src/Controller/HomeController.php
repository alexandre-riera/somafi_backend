<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Service\Kizeo\KizeoClientService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controller principal de l'application
 * 
 * Gère :
 * - Page d'accueil / sélection agence
 * - Liste des clients par agence (via Kizeo)
 * - Page équipements client (avec pagination)
 * 
 * @author Alex - SOMAFI GROUP
 * @version 2.1 - Session 07/02/2026
 */
#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    /** Nombre d'équipements par page par défaut */
    private const EQUIPMENTS_PER_PAGE = 20;

    /** Valeurs autorisées pour le nombre par page */
    private const ALLOWED_PER_PAGE = [20, 50, 100];

    public function __construct(
        private readonly AgencyRepository $agencyRepository,
        private readonly KizeoClientService $kizeoClientService,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Page d'accueil - Redirection selon le profil utilisateur
     */
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Client Contrat Cadre -> redirection vers son espace
        if ($user && $user->isContratCadreUser() && $user->getContratCadre()) {
            return $this->redirectToRoute('app_contrat_cadre_sites', [
                'slug' => $user->getContratCadre()->getSlug(),
            ]);
        }

        // Utilisateur multi-agences -> choix de l'agence
        if ($user && $user->isMultiAgency()) {
            return $this->render('home/select_agency.html.twig', [
                'agencies' => $user->getAgencies(),
            ]);
        }

        // Utilisateur mono-agence -> redirection vers la liste clients
        $agencies = $user ? $user->getAgencies() : [];
        if (!empty($agencies)) {
            return $this->redirectToRoute('app_clients_list', [
                'agencyCode' => $agencies[0],
            ]);
        }

        // Fallback
        return $this->render('home/index.html.twig');
    }

    /**
     * Liste des clients d'une agence
     * 
     * Récupère les clients depuis Kizeo Forms en temps réel
     * et enrichit avec les coordonnées GESTAN si disponibles.
     * La recherche est désormais côté JS (filtrage dynamique).
     */
    #[Route('/agency/{agencyCode}', name: 'app_clients_list', requirements: ['agencyCode' => 'S\d+'])]
    public function clientsList(string $agencyCode, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier l'accès à l'agence
        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé à cette agence');
        }

        // Récupérer l'agence
        $agency = $this->agencyRepository->findOneBy(['code' => $agencyCode, 'isActive' => true]);
        
        if (!$agency) {
            $this->addFlash('error', sprintf('Agence %s non trouvée', $agencyCode));
            return $this->redirectToRoute('app_home');
        }

        // Récupérer tous les clients depuis Kizeo (le filtrage est côté JS)
        $clients = $this->kizeoClientService->getClientsByAgency($agencyCode);

        // Récupérer les coordonnées GESTAN pour enrichissement
        $contactsGestan = $this->getContactsGestan($agencyCode);
        
        // Enrichir les clients avec les données GESTAN
        $clients = $this->kizeoClientService->enrichWithGestanData($clients, $contactsGestan);

        // Compter les équipements par client
        $equipmentCounts = $this->getEquipmentCountsByContact($agencyCode);
        
        // Ajouter le comptage aux clients
        foreach ($clients as &$client) {
            $idContact = $client['id_contact'];
            $client['nb_equipements'] = $equipmentCounts[$idContact] ?? 0;
        }

        return $this->render('home/clients.html.twig', [
            'agency' => $agency,
            'agencyCode' => $agencyCode,
            'clients' => $clients,
            'total_clients' => count($clients),
        ]);
    }

    /**
     * Page équipements d'un client
     * 
     * Affiche les équipements avec filtres année/visite et pagination.
     * Par défaut : dernière visite enregistrée, page 1, 20 items/page.
     */
    #[Route('/agency/{agencyCode}/client/{idContact}', name: 'app_client_equipments', requirements: ['agencyCode' => 'S\d+', 'idContact' => '\d+'])]
    public function clientEquipments(string $agencyCode, int $idContact, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier l'accès à l'agence
        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé à cette agence');
        }

        // Récupérer l'agence
        $agency = $this->agencyRepository->findOneBy(['code' => $agencyCode, 'isActive' => true]);
        
        if (!$agency) {
            $this->addFlash('error', sprintf('Agence %s non trouvée', $agencyCode));
            return $this->redirectToRoute('app_home');
        }

        // Récupérer les infos client depuis Kizeo
        $client = $this->kizeoClientService->getClientByIdContact($agencyCode, $idContact);

        if (!$client) {
            $this->addFlash('error', 'Client non trouvé');
            return $this->redirectToRoute('app_clients_list', ['agencyCode' => $agencyCode]);
        }

        // Enrichir avec GESTAN
        $contactsGestan = $this->getContactsGestan($agencyCode);
        if (isset($contactsGestan[$idContact])) {
            $client = array_merge($client, [
                'adresse' => $contactsGestan[$idContact]['adressep_1'] ?? '',
                'adresse2' => $contactsGestan[$idContact]['adressep_2'] ?? '',
                'telephone' => $contactsGestan[$idContact]['telephone'] ?? '',
                'email' => $contactsGestan[$idContact]['email'] ?? '',
            ]);
        }

        // Récupérer la dernière visite
        $lastVisit = $this->getLastVisit($agencyCode, $idContact);
        
        // Paramètres de filtre (par défaut : dernière visite)
        $annee = $request->query->get('annee', $lastVisit['annee'] ?? date('Y'));
        $visite = $request->query->get('visite', $lastVisit['visite'] ?? 'CE1');

        // Paramètres de pagination
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', self::EQUIPMENTS_PER_PAGE);
        
        // Sécuriser le limit aux valeurs autorisées
        if (!in_array($limit, self::ALLOWED_PER_PAGE, true)) {
            $limit = self::EQUIPMENTS_PER_PAGE;
        }

        // Récupérer les années et visites disponibles
        $availableFilters = $this->getAvailableFilters($agencyCode, $idContact);

        // Récupérer les stats globales pour les compteurs (toujours sur le total)
        $equipmentStats = $this->getEquipmentStats($agencyCode, $idContact, $annee, $visite);

        // Calculer la pagination
        $totalEquipments = $equipmentStats['total'];
        $totalPages = max(1, (int) ceil($totalEquipments / $limit));
        $page = min($page, $totalPages); // Sécuriser si page > max
        $offset = ($page - 1) * $limit;

        // Récupérer les équipements paginés
        $equipments = $this->getEquipmentsByVisit($agencyCode, $idContact, $annee, $visite, $limit, $offset);

        // Récupérer les CR techniciens (PDF Kizeo) pour cette visite
        $technicianReports = $this->getTechnicianReports($agencyCode, $idContact, $annee, $visite);

        return $this->render('home/equipments.html.twig', [
            'agency' => $agency,
            'agencyCode' => $agencyCode,
            'client' => $client,
            'equipments' => $equipments,
            'annee' => $annee,
            'visite' => $visite,
            'available_years' => $availableFilters['years'],
            'available_visits' => $availableFilters['visits'],
            'last_visit' => $lastVisit,
            'technician_reports' => $technicianReports,
            // Pagination
            'current_page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $limit,
            'pagination_offset' => $offset,
            // Stats globales (pour les cards compteurs)
            'equipment_stats' => $equipmentStats,
        ]);
    }

    /**
     * Téléchargement sécurisé d'un CR technicien (PDF)
     * 
     * Les PDF sont dans storage/ (hors public/), on les sert via BinaryFileResponse.
     * Mode inline = aperçu navigateur, mode download = téléchargement.
     */
    #[Route('/agency/{agencyCode}/cr/{jobId}', name: 'app_download_technician_cr', requirements: ['agencyCode' => 'S\d+', 'jobId' => '\d+'])]
    public function downloadTechnicianPdf(string $agencyCode, int $jobId, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier l'accès à l'agence
        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé');
        }

        // Récupérer le job PDF
        try {
            $sql = "SELECT * FROM kizeo_jobs WHERE id = :id AND job_type = 'pdf' AND status = 'done' LIMIT 1";
            $job = $this->connection->fetchAssociative($sql, ['id' => $jobId]);
        } catch (\Exception $e) {
            throw $this->createNotFoundException('CR technicien non trouvé');
        }

        if (!$job) {
            throw $this->createNotFoundException('CR technicien non trouvé');
        }

        // Vérifier que le job appartient bien à cette agence
        if (strtoupper($job['agency_code']) !== strtoupper($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé à ce document');
        }

        // Vérifier que le fichier existe sur le disque
        $filePath = $job['local_path'];
        if (!$filePath || !file_exists($filePath)) {
            // Fallback : reconstruire le chemin depuis le project dir
            $filename = basename($filePath);
            $filePath = $this->getParameter('kernel.project_dir') . '/storage/pdf/' 
                . $agencyCode . '/' . $job['id_contact'] . '/' 
                . $job['annee'] . '/' . $job['visite'] . '/' . $filename;
        }

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Fichier PDF non trouvé sur le serveur');
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'application/pdf');

        $mode = $request->query->get('mode', 'inline');
        $disposition = ($mode === 'download')
            ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
            : ResponseHeaderBag::DISPOSITION_INLINE;

        $response->setContentDisposition($disposition, basename($filePath));

        return $response;
    }

    // =========================================================================
    // MÉTHODES PRIVÉES - Accès BDD
    // =========================================================================

    /**
     * Récupère les contacts GESTAN d'une agence (table contact_sXX)
     * 
     * @return array<int, array> Indexé par id_contact
     */
    private function getContactsGestan(string $agencyCode): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'contact_s' . $tableNumber;

        try {
            $sql = "SELECT id, id_contact, raison_sociale, adressep_1, adressep_2, 
                           cpostalp, villep, telephone, email 
                    FROM {$tableName}";
            
            $results = $this->connection->fetchAllAssociative($sql);
            
            // Indexer par id_contact
            $indexed = [];
            foreach ($results as $row) {
                if ($row['id_contact']) {
                    $indexed[(int) $row['id_contact']] = $row;
                }
            }
            
            return $indexed;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Compte les équipements par client (table equipement_sXX)
     * 
     * @return array<int, int> id_contact => count
     */
    private function getEquipmentCountsByContact(string $agencyCode): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'equipement_s' . $tableNumber;

        try {
            $sql = "SELECT id_contact, COUNT(DISTINCT numero_equipement) as nb 
                    FROM {$tableName} 
                    WHERE is_archive = 0 
                    GROUP BY id_contact";
            
            $results = $this->connection->fetchAllAssociative($sql);
            
            $counts = [];
            foreach ($results as $row) {
                $counts[(int) $row['id_contact']] = (int) $row['nb'];
            }
            
            return $counts;

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Récupère la dernière visite d'un client
     * 
     * @return array{annee: string|null, visite: string|null, date: string|null}
     */
    private function getLastVisit(string $agencyCode, int $idContact): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'equipement_s' . $tableNumber;

        try {
            $sql = "SELECT annee, visite, MAX(date_derniere_visite) as last_date
                    FROM {$tableName}
                    WHERE id_contact = :idContact AND is_archive = 0
                    GROUP BY annee, visite
                    ORDER BY last_date DESC
                    LIMIT 1";
            
            $result = $this->connection->fetchAssociative($sql, ['idContact' => $idContact]);
            
            if ($result) {
                return [
                    'annee' => $result['annee'],
                    'visite' => $result['visite'],
                    'date' => $result['last_date'],
                ];
            }

        } catch (\Exception $e) {
        }

        return ['annee' => null, 'visite' => null, 'date' => null];
    }

    /**
     * Récupère les filtres disponibles (années et visites) pour un client
     * 
     * @return array{years: array<string>, visits: array<string>}
     */
    private function getAvailableFilters(string $agencyCode, int $idContact): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'equipement_s' . $tableNumber;

        $years = [];
        $visits = [];

        try {
            // Années disponibles
            $sql = "SELECT DISTINCT annee FROM {$tableName} 
                    WHERE id_contact = :idContact AND annee IS NOT NULL AND is_archive = 0
                    ORDER BY annee DESC";
            $results = $this->connection->fetchAllAssociative($sql, ['idContact' => $idContact]);
            $years = array_column($results, 'annee');

            // Visites disponibles
            $sql = "SELECT DISTINCT visite FROM {$tableName} 
                    WHERE id_contact = :idContact AND is_archive = 0
                    ORDER BY visite";
            $results = $this->connection->fetchAllAssociative($sql, ['idContact' => $idContact]);
            $visits = array_column($results, 'visite');

        } catch (\Exception $e) {
        }

        return [
            'years' => $years,
            'visits' => $visits,
        ];
    }

    /**
     * Récupère les statistiques des équipements pour une visite (total, au contrat, hors contrat)
     * Requête COUNT séparée pour ne pas dépendre de la pagination.
     * 
     * @return array{total: int, au_contrat: int, hors_contrat: int}
     */
    private function getEquipmentStats(string $agencyCode, int $idContact, string $annee, string $visite): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'equipement_s' . $tableNumber;

        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN is_hors_contrat = 0 THEN 1 ELSE 0 END) as au_contrat,
                        SUM(CASE WHEN is_hors_contrat = 1 THEN 1 ELSE 0 END) as hors_contrat
                    FROM {$tableName}
                    WHERE id_contact = :idContact 
                      AND annee = :annee 
                      AND visite = :visite
                      AND is_archive = 0";

            $result = $this->connection->fetchAssociative($sql, [
                'idContact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
            ]);

            if ($result) {
                return [
                    'total' => (int) $result['total'],
                    'au_contrat' => (int) $result['au_contrat'],
                    'hors_contrat' => (int) $result['hors_contrat'],
                ];
            }

        } catch (\Exception $e) {
        }

        return ['total' => 0, 'au_contrat' => 0, 'hors_contrat' => 0];
    }

    /**
     * Récupère les équipements d'un client pour une visite/année donnée (avec pagination)
     * 
     * @return array<int, array>
     */
    private function getEquipmentsByVisit(string $agencyCode, int $idContact, string $annee, string $visite, int $limit = self::EQUIPMENTS_PER_PAGE, int $offset = 0): array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'equipement_s' . $tableNumber;

        try {
            $sql = "SELECT * FROM {$tableName}
                    WHERE id_contact = :idContact 
                      AND annee = :annee 
                      AND visite = :visite
                      AND is_archive = 0
                    ORDER BY is_hors_contrat ASC, numero_equipement ASC
                    LIMIT :limit OFFSET :offset";
            
            return $this->connection->fetchAllAssociative($sql, [
                'idContact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
                'limit' => $limit,
                'offset' => $offset,
            ], [
                'idContact' => \Doctrine\DBAL\ParameterType::INTEGER,
                'annee' => \Doctrine\DBAL\ParameterType::STRING,
                'visite' => \Doctrine\DBAL\ParameterType::STRING,
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Récupère les CR techniciens (PDF) disponibles pour un client/visite
     * Source : table kizeo_jobs (jobs PDF terminés avec succès)
     * 
     * @return array Liste des CR avec id, data_id, client_name, local_path, file_size, completed_at
     */
    private function getTechnicianReports(string $agencyCode, int $idContact, string $annee, string $visite): array
    {
        try {
            $sql = "SELECT id, data_id, form_id, client_name, local_path, file_size, completed_at, annee, visite
                    FROM kizeo_jobs
                    WHERE job_type = 'pdf'
                    AND status = 'done'
                    AND agency_code = :agency_code
                    AND id_contact = :id_contact
                    AND annee = :annee
                    AND visite = :visite
                    ORDER BY completed_at DESC";

            return $this->connection->fetchAllAssociative($sql, [
                'agency_code' => strtoupper($agencyCode),
                'id_contact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
            ]);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Extrait le numéro de table depuis le code agence (S100 -> 100)
     */
    private function extractTableNumber(string $agencyCode): string
    {
        return ltrim($agencyCode, 'Ss');
    }
}