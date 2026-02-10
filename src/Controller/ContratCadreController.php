<?php

namespace App\Controller;

use App\Service\ContratCadreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        // Récupérer le contrat cadre
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier l'accès (si authentifié)
        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        // Récupérer tous les sites
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
        // Récupérer le contrat cadre
        $contratCadre = $this->contratCadreService->getContratCadreBySlug($slug);

        if (!$contratCadre) {
            throw $this->createNotFoundException('Contrat cadre non trouvé');
        }

        // Vérifier l'accès
        $user = $this->getUser();
        if ($user && !$this->contratCadreService->userHasAccessToContratCadre($user, $contratCadre)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat cadre');
        }

        // Récupérer le site
        $site = $this->contratCadreService->getSite($contratCadre, $agencyCode, $idContact);

        if (!$site) {
            throw $this->createNotFoundException('Site non trouvé ou non autorisé');
        }

        // Récupérer les filtres
        $annee = $request->query->get('annee');
        $visite = $request->query->get('visite');

        // Pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', 20);
        // Valeurs autorisées
        if (!in_array($perPage, [20, 50, 100])) {
            $perPage = 20;
        }

        // Récupérer les équipements avec pagination
        $equipmentsData = $this->contratCadreService->getEquipmentsForSite(
            $agencyCode, 
            $idContact, 
            $annee, 
            $visite,
            $page,
            $perPage
        );

        // Récupérer les fichiers CC disponibles
        $files = $this->contratCadreService->getFilesForContact($agencyCode, $idContact);

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
        ]);
    }

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

        // Vérifier que le site appartient bien au CC
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
}
