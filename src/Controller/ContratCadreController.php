<?php

namespace App\Controller;

use App\Service\ContratCadreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        private readonly ContratCadreService $contratCadreService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    // ========================================================================
    //  PAGES
    // ========================================================================

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

        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        $site = $this->contratCadreService->getSite($contratCadre, $agencyCode, $idContact);

        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé ou non autorisé');
        }

        // Filtres
        $annee = $request->query->get('annee');
        $visite = $request->query->get('visite');

        // Pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', 20);
        if (!in_array($perPage, [20, 50, 100])) {
            $perPage = 20;
        }

        $equipmentsData = $this->contratCadreService->getEquipmentsForSite(
            $agencyCode,
            $idContact,
            $annee,
            $visite,
            $page,
            $perPage
        );

        $files = $this->contratCadreService->getFilesForContact($agencyCode, $idContact);
        $isUserCcAdmin = $this->contratCadreService->isUserCcAdmin($user, $contratCadre);

        return $this->render('contrat_cadre/site_detail.html.twig', [
            'contrat_cadre' => $contratCadre,
            'site' => $site,
            'equipments' => $equipmentsData['equipments'],
            'years' => $equipmentsData['years'],
            'visits' => $equipmentsData['visits'],
            'current_year' => $equipmentsData['current_year'],
            'current_visit' => $equipmentsData['current_visit'],
            'stats' => $equipmentsData['stats'],
            'pagination' => $equipmentsData['pagination'],
            'files' => $files,
            'agency_code' => strtoupper($agencyCode),
            'id_contact' => $idContact,
            'is_user_cc_admin' => $isUserCcAdmin,
        ]);
    }

    // ========================================================================
    //  FICHIERS - Upload / Download / Delete
    // ========================================================================

    /**
     * Upload d'un fichier PDF pour un site CC
     */
    #[Route('/{slug}/site/{agencyCode}/{idContact}/upload', name: 'app_contrat_cadre_upload', methods: ['POST'])]
    public function upload(
        string $slug,
        string $agencyCode,
        string $idContact,
        Request $request
    ): Response {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        $user = $this->getUser();

        // Vérifier que l'utilisateur est admin CC
        if (!$this->contratCadreService->isUserCcAdmin($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Seuls les administrateurs peuvent uploader des fichiers.');
        }

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('cc_upload', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
        }

        $file = $request->files->get('file');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
        }

        $result = $this->contratCadreService->uploadFile(
            $file,
            $contratCadre,
            $agencyCode,
            $idContact,
            $user->getId()
        );

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $this->redirectToSiteDetail($slug, $agencyCode, $idContact);
    }

    /**
     * Téléchargement d'un fichier CC
     */
    #[Route('/{slug}/file/{fileId}/download', name: 'app_contrat_cadre_download', methods: ['GET'])]
    public function download(string $slug, int $fileId): Response
    {
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        $file = $this->contratCadreService->getFileById($fileId);

        if (!$file) {
            throw $this->createNotFoundException('Fichier non trouvé.');
        }

        // Vérifier que le fichier appartient bien à ce CC
        if ((int) $file['contrat_cadre_id'] !== $contratCadre->getId()) {
            throw $this->createAccessDeniedException('Ce fichier ne fait pas partie de ce contrat cadre.');
        }

        $absolutePath = $this->projectDir . '/public/uploads/cc/' . $file['path'];

        if (!file_exists($absolutePath)) {
            throw $this->createNotFoundException('Le fichier physique est introuvable.');
        }

        $response = new BinaryFileResponse($absolutePath);
        $downloadName = $file['original_name'] ?? ($file['name'] . '.pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $downloadName);

        return $response;
    }

    /**
     * Suppression d'un fichier CC (admin uniquement)
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

        $user = $this->getUser();

        if (!$this->contratCadreService->isUserCcAdmin($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Seuls les administrateurs peuvent supprimer des fichiers.');
        }

        // Vérifier CSRF
        if (!$this->isCsrfTokenValid('delete_cc_file', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_contrat_cadre_sites', ['slug' => $slug]);
        }

        // Récupérer le fichier pour la redirection
        $file = $this->contratCadreService->getFileById($fileId);

        $success = $this->contratCadreService->deleteFile($fileId);

        if ($success) {
            $this->addFlash('success', 'Fichier supprimé avec succès.');
        } else {
            $this->addFlash('error', 'Erreur lors de la suppression du fichier.');
        }

        // Rediriger vers le site détail si on a les infos, sinon vers la liste
        if ($file) {
            return $this->redirectToSiteDetail($slug, $file['code_agence'], $file['id_contact']);
        }

        return $this->redirectToRoute('app_contrat_cadre_sites', ['slug' => $slug]);
    }

    // ========================================================================
    //  API JSON
    // ========================================================================

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
            $sites = array_filter($sites, function ($site) use ($search) {
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
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', 20);
        if (!in_array($perPage, [20, 50, 100])) {
            $perPage = 20;
        }

        $data = $this->contratCadreService->getEquipmentsForSite($agencyCode, $idContact, $annee, $visite, $page, $perPage);

        return $this->json($data);
    }

    // ========================================================================
    //  HELPERS
    // ========================================================================

    /**
     * Redirection vers la page détail d'un site
     */
    private function redirectToSiteDetail(string $slug, string $agencyCode, string $idContact): Response
    {
        return $this->redirectToRoute('app_contrat_cadre_site_detail', [
            'slug' => $slug,
            'agencyCode' => $agencyCode,
            'idContact' => $idContact,
        ]);
    }

    protected function getUser(): ?\App\Entity\User
    {
        return parent::getUser();
    }
}
