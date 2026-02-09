<?php

namespace App\Controller;

use App\Service\ContratCadreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur pour le portail Contrat Cadre
 * 
 * Gère l'affichage des sites et équipements pour les clients
 * ayant un contrat cadre multi-agences (ex: Mondial Relay, Kuehne, XPO)
 */
#[Route('/cc')]
class ContratCadreController extends AbstractController
{
    public function __construct(
        private readonly ContratCadreService $contratCadreService
    ) {
    }

    /**
     * Page d'accueil du contrat cadre - Liste de tous les sites
     */
    #[Route('/{slug}', name: 'app_contrat_cadre_sites', methods: ['GET'])]
    public function sites(string $slug): Response
    {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier l'accès
        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        $sites = $this->contratCadreService->findAllSitesForContratCadre($contratCadre);
        $sitesByAgency = $this->contratCadreService->countSitesByAgency($sites);

        return $this->render('contrat_cadre/sites.html.twig', [
            'contrat_cadre' => $contratCadre,
            'sites' => $sites,
            'sites_by_agency' => $sitesByAgency,
            'total_sites' => count($sites),
        ]);
    }

    /**
     * Page détail d'un site - Équipements et fichiers
     */
    #[Route('/{slug}/site/{agencyCode}/{idContact}', name: 'app_contrat_cadre_site_detail', methods: ['GET'])]
    public function siteDetail(
        string $slug, 
        string $agencyCode, 
        string $idContact,
        Request $request
    ): Response {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier l'accès
        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        $site = $this->contratCadreService->getSite($contratCadre, $agencyCode, $idContact);

        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé ou non autorisé');
        }

        // Récupérer les filtres
        $annee = $request->query->get('annee');
        $visite = $request->query->get('visite');

        // Récupérer les équipements
        $equipmentsData = $this->contratCadreService->getEquipmentsForSite(
            $agencyCode, 
            $idContact, 
            $annee, 
            $visite
        );

        // Récupérer les fichiers CC disponibles
        $files = $this->contratCadreService->getFilesForContact($agencyCode, $idContact);

        // Déterminer si l'utilisateur peut uploader (admin CC ou admin global)
        $canUpload = $this->isUserCcAdmin($contratCadre);

        return $this->render('contrat_cadre/site_detail.html.twig', [
            'contrat_cadre' => $contratCadre,
            'site' => $site,
            'equipments' => $equipmentsData['equipments'],
            'years' => $equipmentsData['years'],
            'visits' => $equipmentsData['visits'],
            'current_year' => $equipmentsData['current_year'],
            'current_visit' => $equipmentsData['current_visit'],
            'stats' => $equipmentsData['stats'],
            'files' => $files,
            'agency_code' => strtoupper($agencyCode),
            'id_contact' => $idContact,
            'can_upload' => $canUpload,
        ]);
    }

    // =========================================================================
    // PHASE 1C - Upload / Download / Delete de fichiers CR clients
    // =========================================================================

    /**
     * Upload d'un fichier CR client (PDF)
     * 
     * Accessible uniquement aux admins CC (ROLE_ADMIN, ROLE_CC_ADMIN, {SLUG}_ADMIN)
     */
    #[Route('/{slug}/site/{agencyCode}/{idContact}/upload', name: 'app_contrat_cadre_upload', methods: ['POST'])]
    public function uploadFile(
        string $slug,
        string $agencyCode,
        string $idContact,
        Request $request
    ): Response {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier droits admin CC
        if (!$this->isUserCcAdmin($contratCadre)) {
            throw $this->createAccessDeniedException('Seuls les administrateurs peuvent uploader des fichiers');
        }

        // Vérifier que le site existe et appartient au CC
        $site = $this->contratCadreService->getSite($contratCadre, $agencyCode, $idContact);
        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé');
        }

        // Récupérer le fichier uploadé
        $uploadedFile = $request->files->get('cr_file');

        if (!$uploadedFile) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
        }

        // Validation : PDF uniquement, max 20 Mo
        $allowedMimeTypes = ['application/pdf'];
        $maxSize = 20 * 1024 * 1024; // 20 Mo

        if (!in_array($uploadedFile->getMimeType(), $allowedMimeTypes)) {
            $this->addFlash('error', 'Seuls les fichiers PDF sont acceptés.');
            return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
        }

        if ($uploadedFile->getSize() > $maxSize) {
            $this->addFlash('error', 'Le fichier ne doit pas dépasser 20 Mo.');
            return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
        }

        // Récupérer le nom personnalisé (optionnel)
        $customName = trim($request->request->get('file_name', ''));

        try {
            $user = $this->getUser();
            $result = $this->contratCadreService->uploadFileForContact(
                contratCadre: $contratCadre,
                agencyCode: $agencyCode,
                idContact: $idContact,
                uploadedFile: $uploadedFile,
                uploadedBy: $user,
                customName: $customName ?: null
            );

            $this->addFlash('success', 'Fichier "' . $result['name'] . '" uploadé avec succès.');

        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'upload : ' . $e->getMessage());
        }

        return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
    }

    /**
     * Téléchargement d'un fichier CR client
     */
    #[Route('/{slug}/file/{fileId}/download', name: 'app_contrat_cadre_download', methods: ['GET'])]
    public function downloadFile(string $slug, int $fileId): Response
    {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier l'accès au CC
        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        // Récupérer le fichier
        $file = $this->contratCadreService->getFileById($fileId);

        if (!$file) {
            throw $this->createNotFoundException('Fichier non trouvé');
        }

        // Construire le chemin complet du fichier
        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/public/uploads/cc/' . $file['path'];

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Fichier introuvable sur le serveur');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['original_name'] ?? $file['name']
        );

        return $response;
    }

    /**
     * Suppression d'un fichier CR client (admin CC uniquement)
     */
    #[Route('/{slug}/file/{fileId}/delete', name: 'app_contrat_cadre_delete_file', methods: ['POST'])]
    public function deleteFile(
        string $slug, 
        int $fileId,
        Request $request
    ): Response {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier droits admin CC
        if (!$this->isUserCcAdmin($contratCadre)) {
            throw $this->createAccessDeniedException('Seuls les administrateurs peuvent supprimer des fichiers');
        }

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('delete_cc_file', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirect($request->headers->get('referer', '/'));
        }

        try {
            $projectDir = $this->getParameter('kernel.project_dir');
            $file = $this->contratCadreService->deleteFile($fileId, $projectDir . '/public/uploads/cc/');
            
            $this->addFlash('success', 'Fichier "' . ($file['name'] ?? 'inconnu') . '" supprimé.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression : ' . $e->getMessage());
        }

        return $this->redirect($request->headers->get('referer', '/'));
    }

    // =========================================================================
    // API JSON
    // =========================================================================

    /**
     * API JSON - Liste des sites (pour recherche dynamique)
     */
    #[Route('/{slug}/api/sites', name: 'app_contrat_cadre_api_sites', methods: ['GET'])]
    public function apiSites(string $slug, Request $request): Response
    {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            return $this->json(['error' => 'Contrat cadre non trouvé'], 404);
        }

        $sites = $this->contratCadreService->findAllSitesForContratCadre($contratCadre);

        // Filtre optionnel par recherche
        $search = $request->query->get('q', '');
        if (!empty($search)) {
            $search = mb_strtolower($search);
            $sites = array_filter($sites, function($site) use ($search) {
                return str_contains(mb_strtolower($site['raison_sociale'] ?? ''), $search)
                    || str_contains(mb_strtolower($site['villep'] ?? ''), $search)
                    || str_contains(mb_strtolower($site['cpostalp'] ?? ''), $search);
            });
        }

        // Filtre optionnel par agence
        $agencyFilter = $request->query->get('agency');
        if (!empty($agencyFilter)) {
            $sites = array_filter($sites, fn($s) => $s['agency_code'] === strtoupper($agencyFilter));
        }

        return $this->json([
            'total' => count($sites),
            'sites' => array_values($sites)
        ]);
    }

    /**
     * API JSON - Équipements d'un site
     */
    #[Route('/{slug}/api/site/{agencyCode}/{idContact}/equipments', name: 'app_contrat_cadre_api_equipments', methods: ['GET'])]
    public function apiEquipments(
        string $slug,
        string $agencyCode,
        string $idContact,
        Request $request
    ): Response {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            return $this->json(['error' => 'Contrat cadre non trouvé'], 404);
        }

        $site = $this->contratCadreService->getSite($contratCadre, $agencyCode, $idContact);
        if (!$site) {
            return $this->json(['error' => 'Site non autorisé'], 403);
        }

        $annee = $request->query->get('annee');
        $visite = $request->query->get('visite');

        $data = $this->contratCadreService->getEquipmentsForSite($agencyCode, $idContact, $annee, $visite);

        return $this->json($data);
    }

    // =========================================================================
    // Méthodes privées
    // =========================================================================

    /**
     * Vérifie si l'utilisateur courant est admin CC
     * (ROLE_ADMIN, ROLE_CC_ADMIN, ou {SLUG}_ADMIN)
     */
    private function isUserCcAdmin($contratCadre): bool
    {
        $user = $this->getUser();
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

    /**
     * Redirige vers la page détail d'un site
     */
    private function redirectToSiteDetail(string $slug, string $agencyCode, string $idContact): Response
    {
        return $this->redirectToRoute('app_contrat_cadre_site_detail', [
            'slug' => $slug,
            'agencyCode' => $agencyCode,
            'idContact' => $idContact,
        ]);
    }
}
