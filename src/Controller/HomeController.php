<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AgencyRepository;
use App\Service\Kizeo\KizeoClientService;
use App\Service\Pdf\ClientReportGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller principal de l'application
 * 
 * Gère :
 * - Page d'accueil / sélection agence
 * - Liste des clients par agence (via Kizeo)
 * - Page équipements client (avec pagination)
 * - Génération PDF Compte Rendu Client
 * 
 * @author Alex - SOMAFI GROUP
 * @version 2.2 - Session 10/02/2026
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
        $ccUserEntries = $user->getContratCadresUser();
        if (!$ccUserEntries->isEmpty() && !$user->isAdmin() && !$user->isAdminAgence()) {
            return $this->redirectToRoute('app_contrat_cadre_sites', [
                'slug' => $ccUserEntries->first()->getContratCadre()->getSlug(),
            ]);
        }

        // Utilisateur multi-agences -> choix de l'agence
        if ($user && $user->isMultiAgency()) {
            $agencies = $this->agencyRepository->findBy([
                'code' => $user->getAgencies(),
                'isActive' => true,
            ]);
        
            return $this->render('home/select_agency.html.twig', [
                'agencies' => $agencies,
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

    // =========================================================================
    // GÉNÉRATION PDF COMPTE RENDU CLIENT
    // =========================================================================

    /**
     * Génère le PDF Compte Rendu Client
     * 
     * Appelé en AJAX depuis le bouton "Générer PDF complet" de la page équipements.
     * Charge les données client depuis GESTAN (contact_sXX) pour avoir les adresses complètes.
     * Retourne le PDF en téléchargement ou un JSON d'erreur.
     */
    #[Route(
        '/agency/{agencyCode}/client/{idContact}/generate-pdf',
        name: 'app_generate_client_pdf',
        requirements: ['agencyCode' => 'S\d+', 'idContact' => '\d+'],
        methods: ['POST']
    )]
    public function generateClientPdf(
        Request $request,
        string $agencyCode,
        int $idContact,
        ClientReportGenerator $clientReportGenerator
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier l'accès à l'agence
        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Accès non autorisé à cette agence.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Récupérer les paramètres depuis le body (POST JSON ou form-data)
        $annee = $request->request->get('annee', date('Y'));
        $visite = $request->request->get('visite', 'CE1');
        $includePhotos = $request->request->getBoolean('include_photos', true);

        // Charger les données client depuis GESTAN (contact_sXX)
        // Le template PDF a besoin de : raison_sociale, adressep_1, adressep_2, cpostalp, villep
        $clientData = $this->loadClientDataForPdf($agencyCode, $idContact);

        if (!$clientData) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Client non trouvé dans la base GESTAN.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            // Générer le PDF
            $pdfPath = $clientReportGenerator->generate(
                $agencyCode,
                $idContact,
                $clientData,
                $annee,
                $visite,
                $includePhotos
            );

            // Vérifier que le fichier a bien été créé
            if (!file_exists($pdfPath)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Erreur lors de la génération du PDF.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Retourner le PDF en téléchargement
            $response = new BinaryFileResponse($pdfPath);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                basename($pdfPath)
            );

            return $response;

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de la génération du PDF : ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Charge les données complètes du client depuis GESTAN (contact_sXX)
     * pour le PDF CR client.
     * 
     * On enrichit aussi avec les données Kizeo (raison_sociale) au cas où 
     * le contact GESTAN n'a pas toutes les infos.
     * 
     * @return array<string, mixed>|null
     */
    private function loadClientDataForPdf(string $agencyCode, int $idContact): ?array
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'contact_s' . $tableNumber;

        try {
            $sql = "SELECT id, id_contact, raison_sociale, adressep_1, adressep_2, 
                           cpostalp, villep, telephone, email 
                    FROM {$tableName}
                    WHERE id_contact = :idContact
                    LIMIT 1";

            $clientData = $this->connection->fetchAssociative($sql, ['idContact' => $idContact]);

            if ($clientData) {
                return $clientData;
            }
        } catch (\Exception $e) {
            // Fallback ci-dessous
        }

        // Fallback : essayer via Kizeo
        $kizeoClient = $this->kizeoClientService->getClientByIdContact($agencyCode, $idContact);
        if ($kizeoClient) {
            return [
                'id_contact' => $idContact,
                'raison_sociale' => $kizeoClient['raison_sociale'] ?? 'Client #' . $idContact,
                'adressep_1' => $kizeoClient['adresse'] ?? '',
                'adressep_2' => '',
                'cpostalp' => $kizeoClient['code_postal'] ?? '',
                'villep' => $kizeoClient['ville'] ?? '',
                'telephone' => $kizeoClient['telephone'] ?? '',
                'email' => $kizeoClient['email'] ?? '',
            ];
        }

        return null;
    }

    // =========================================================================
    // TÉLÉCHARGEMENT CR TECHNICIEN
    // =========================================================================

    /**
     * Téléchargement sécurisé d'un CR technicien (PDF)
     * 
     * Les PDF sont dans storage/ (hors public/), on les sert via BinaryFileResponse.
     * Mode inline = aperçu navigateur, mode download = téléchargement.
     * 
     * Sécurité : le path est reconstruit à partir des paramètres validés par les requirements,
     * pas depuis un input utilisateur libre.
     */
    #[Route('/agency/{agencyCode}/cr/{idContact}/{annee}/{visite}/{filename}', 
        name: 'app_download_technician_cr', 
        requirements: [
            'agencyCode' => 'S\d+', 
            'idContact' => '\d+',
            'annee' => '\d{4}',
            'visite' => 'CEA|CE1|CE2|CE3|CE4',
            'filename' => '.+\.pdf'
        ]
    )]
    public function downloadTechnicianPdf(
        string $agencyCode, 
        int $idContact, 
        string $annee, 
        string $visite, 
        string $filename,
        Request $request
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Vérifier l'accès à l'agence
        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé');
        }

        // Reconstruire le chemin complet (sécurisé car chaque segment est validé par les requirements)
        $filePath = sprintf(
            '%s/storage/pdf/%s/%d/%s/%s/%s',
            $this->getParameter('kernel.project_dir'),
            strtoupper($agencyCode),
            $idContact,
            $annee,
            strtoupper($visite),
            $filename
        );

        // Sécurité supplémentaire : vérifier qu'il n'y a pas de traversal
        $realPath = realpath($filePath);
        $storageBase = realpath($this->getParameter('kernel.project_dir') . '/storage/pdf');
        
        if (!$realPath || !$storageBase || !str_starts_with($realPath, $storageBase)) {
            throw $this->createNotFoundException('CR technicien non trouvé');
        }

        if (!file_exists($realPath)) {
            throw $this->createNotFoundException('Fichier PDF non trouvé sur le serveur');
        }

        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', 'application/pdf');

        $mode = $request->query->get('mode', 'inline');
        $disposition = ($mode === 'download')
            ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
            : ResponseHeaderBag::DISPOSITION_INLINE;

        $response->setContentDisposition($disposition, $filename);

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
     * Récupère les CR techniciens (PDF) en scannant le filesystem
     * Plus de dépendance à kizeo_jobs — fonctionne même après purge
     * 
     * Structure attendue : storage/pdf/{agencyCode}/{idContact}/{annee}/{visite}/*.pdf
     * 
     * @return array Liste des CR avec filename, filepath, filesize, modified_at
     */
    private function getTechnicianReports(string $agencyCode, int $idContact, string $annee, string $visite): array
    {
        $dir = sprintf(
            '%s/storage/pdf/%s/%d/%s/%s',
            $this->getParameter('kernel.project_dir'),
            strtoupper($agencyCode),
            $idContact,
            $annee,
            strtoupper($visite)
        );

        if (!is_dir($dir)) {
            return [];
        }

        $reports = [];
        $files = glob($dir . '/*.pdf');

        if ($files === false) {
            return [];
        }

        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $reports[] = [
                'filename' => $filename,
                'filesize' => filesize($filepath),
                'modified_at' => date('Y-m-d H:i', filemtime($filepath)),
            ];
        }

        // Tri par nom de fichier (alphabétique décroissant)
        usort($reports, fn($a, $b) => strcmp($b['filename'], $a['filename']));

        return $reports;
    }

    /**
     * Extrait le numéro de table depuis le code agence (S100 -> 100)
     */
    private function extractTableNumber(string $agencyCode): string
    {
        return ltrim($agencyCode, 'Ss');
    }
}