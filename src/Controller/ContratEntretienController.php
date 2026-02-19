<?php

namespace App\Controller;

use App\Security\Voter\ContratEntretienVoter;
use App\Service\ContratEntretienService;
use App\Service\ContactService;
use App\Service\Kizeo\KizeoClientListSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\DTO\ContactDTO;
use App\Form\ContactType;
use App\DTO\ContratEntretienDTO;
use App\Form\ContratEntretienType;
use App\Service\ContratPdfService;

/**
 * Contrôleur pour la gestion des contrats d'entretien.
 *
 * Toutes les routes sont préfixées par /contrats/{agencyCode}.
 * La résolution de l'agence est dynamique : le code agence dans l'URL
 * détermine les tables DBAL interrogées (contrat_sXX, contact_sXX…).
 *
 * Sécurité : chaque action est protégée par le ContratEntretienVoter.
 */
#[Route('/contrats')]
class ContratEntretienController extends AbstractController
{
    public function __construct(
        private readonly ContratEntretienService $contratService,
        private readonly ContactService $contactService,
        private readonly KizeoClientListSyncService $kizeoClientSync,
        private readonly ContratPdfService $contratPdfService,
    ) {
    }

    // =========================================================================
    //  INDEX — Listing des contrats d'une agence
    // =========================================================================

    /**
     * Liste paginée des contrats d'entretien pour une agence.
     *
     * URL : /contrats/{agencyCode}
     * Ex  : /contrats/S100
     *
     * Filtres query-string : ?statut=actif&search=mondial&sort=date_fin&order=ASC&page=2
     */
    #[Route('/{agencyCode}', name: 'app_contrat_entretien_index', methods: ['GET'])]
    public function index(string $agencyCode, Request $request): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        // Vérification Voter : l'utilisateur peut-il lister les contrats de cette agence ?
        $this->denyAccessUnlessGranted(ContratEntretienVoter::LIST, $agencyCode);

        // Récupérer les infos de l'agence
        $agency = $this->contratService->getAgencyInfo($agencyCode);

        // Filtres
        $filters = [
            'statut' => $request->query->get('statut', ''),
            'search' => $request->query->get('search', ''),
            'sort'   => $request->query->get('sort', ''),
            'order'  => $request->query->get('order', 'DESC'),
        ];

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = 20;

        // Données
        $result = $this->contratService->getContratsPaginated($agencyCode, $filters, $page, $perPage);
        $stats  = $this->contratService->getAgencyStats($agencyCode);

        // Agences accessibles (pour le sélecteur dans le template)
        $accessibleAgencies = $this->contratService->getAccessibleAgencies($this->getUser());

        return $this->render('contrat_entretien/index.html.twig', [
            'agencyCode'         => $agencyCode,
            'agency'             => $agency,
            'contrats'           => $result['contrats'],
            'total'              => $result['total'],
            'pages'              => $result['pages'],
            'currentPage'        => $page,
            'filters'            => $filters,
            'stats'              => $stats,
            'accessibleAgencies' => $accessibleAgencies,
            'statuts'            => ContratEntretienService::STATUTS,
        ]);
    }

    // =========================================================================
    //  SHOW — Détail d'un contrat
    // =========================================================================

    /**
     * Page de détail d'un contrat d'entretien.
     *
     * URL : /contrats/{agencyCode}/{id}
     * Ex  : /contrats/S100/42
     */
    #[Route('/{agencyCode}/{id}', name: 'app_contrat_entretien_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $agencyCode, int $id): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat) {
            throw $this->createNotFoundException("Contrat #{$id} introuvable sur l'agence {$agencyCode}.");
        }

        // Vérification Voter (on passe le code agence ; le Voter vérifie l'appartenance)
        $this->denyAccessUnlessGranted(ContratEntretienVoter::VIEW, $agencyCode);

        // Avenants
        $avenants = $this->contratService->getAvenantsByContratId($agencyCode, $id);

        // Statistiques équipements (via id_contact du client)
        $equipStats = [];
        if (!empty($contrat['id_contact'])) {
            $equipStats = $this->contratService->countEquipementsForContrat(
                $agencyCode,
                $contrat['id_contact']
            );
        }

        // Infos agence
        $agency = $this->contratService->getAgencyInfo($agencyCode);

        return $this->render('contrat_entretien/show.html.twig', [
            'agencyCode'  => $agencyCode,
            'agency'      => $agency,
            'contrat'     => $contrat,
            'avenants'    => $avenants,
            'equipStats'  => $equipStats,
        ]);
    }

    // =========================================================================
    //  CREATE — Formulaire de création (squelette Phase 2)
    // =========================================================================

    /**
     * Formulaire de création d'un contrat d'entretien.
     *
     * URL : /contrats/{agencyCode}/new
     *
     * NOTE : Le formulaire complet (ContratEntretienType) sera implémenté
     *        en Phase 2. Cette action pose le squelette de la route et du template.
     */
    #[Route('/{agencyCode}/new', name: 'app_contrat_entretien_create', methods: ['GET', 'POST'])]
    public function create(string $agencyCode, Request $request): Response
    {
        $agencyCode = strtoupper($agencyCode);

        $this->denyAccessUnlessGranted(ContratEntretienVoter::CREATE, $agencyCode);

        $dto = new ContratEntretienDTO();

        // Pré-remplissage si on vient de la création client (save_and_contrat)
        $contactId = $request->query->get('contactId');
        $idContact = $request->query->get('idContact');
        if ($contactId && $idContact) {
            $dto->contactId = (int) $contactId;
            $dto->idContact = $idContact;
        }

        // Auto-suggérer le prochain numéro de contrat
        $dto->numeroContrat = $this->contratService->getNextNumeroContrat($agencyCode);

        $form = $this->createForm(ContratEntretienType::class, $dto);
        $form->handleRequest($request);

        // Récupérer les infos client pour affichage
        $clientInfo = null;
        if ($dto->contactId) {
            $clientInfo = $this->contactService->findById($agencyCode, $dto->contactId);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // 0. Vérifier que le client existe
                $client = $this->contactService->findById($agencyCode, $dto->contactId);
                if (!$client) {
                    $this->addFlash('danger', sprintf(
                        'Client introuvable (contact_id: %d) sur l\'agence %s. Créez d\'abord le client.',
                        $dto->contactId,
                        $agencyCode
                    ));
                    return $this->render('contrat_entretien/create.html.twig', [
                        'form' => $form->createView(),
                        'agencyCode' => $agencyCode,
                        'clientInfo' => $clientInfo,
                        'dto' => $dto,
                    ]);
                }
                // 1. Upload PDF si fourni
                $pdfPath = null;
                if ($dto->contratPdfFile) {
                    $pdfPath = $this->contratPdfService->uploadContratPdf(
                        $agencyCode,
                        $dto->idContact,
                        $dto->numeroContrat,
                        $dto->contratPdfFile
                    );
                }

                // 2. Insertion en BDD
                $userId = $this->getUser()?->getId();
                $contratId = $this->contratService->insertContrat(
                    $agencyCode,
                    $dto,
                    $userId,
                    $pdfPath
                );

                $this->addFlash('success', sprintf(
                    'Contrat n°%d créé avec succès pour le client %s.',
                    $dto->numeroContrat,
                    $clientInfo['raison_sociale'] ?? $dto->idContact
                ));

                $action = $request->request->get('action', 'save_and_show');

                if ($action === 'save_and_equipements') {
                    // Redirection vers la génération d'équipements (Phase 2.6)
                    return $this->redirectToRoute('app_contrat_entretien_show', [
                        'agencyCode' => $agencyCode,
                        'id' => $contratId,
                    ]);
                }

                return $this->redirectToRoute('app_contrat_entretien_show', [
                    'agencyCode' => $agencyCode,
                    'id' => $contratId,
                ]);

            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur lors de la création du contrat : ' . $e->getMessage());
            }
        }

        return $this->render('contrat_entretien/create.html.twig', [
            'form' => $form->createView(),
            'agencyCode' => $agencyCode,
            'clientInfo' => $clientInfo,
            'dto' => $dto,
        ]);
    }

    // =========================================================================
    //  EDIT — Formulaire d'édition (squelette Phase 2)
    // =========================================================================

    /**
     * Formulaire d'édition d'un contrat existant.
     *
     * URL : /contrats/{agencyCode}/{id}/edit
     */
    #[Route('/{agencyCode}/{id}/edit', name: 'app_contrat_entretien_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $agencyCode, int $id, Request $request): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat) {
            throw $this->createNotFoundException("Contrat #{$id} introuvable sur l'agence {$agencyCode}.");
        }

        // Vérification Voter
        $this->denyAccessUnlessGranted(ContratEntretienVoter::EDIT, $agencyCode);

        $agency = $this->contratService->getAgencyInfo($agencyCode);

        // Hydrater le DTO depuis les données existantes
        $dto = ContratEntretienDTO::fromArray($contrat);

        $form = $this->createForm(ContratEntretienType::class, $dto);
        $form->handleRequest($request);

        // Infos client pour le bandeau
        $clientInfo = null;
        if ($dto->contactId) {
            $clientInfo = $this->contactService->findById($agencyCode, $dto->contactId);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // 1. Upload PDF si un nouveau fichier est fourni
                $pdfPath = null;
                if ($dto->contratPdfFile) {
                    $pdfPath = $this->contratPdfService->uploadContratPdf(
                        $agencyCode,
                        $contrat['id_contact'],
                        $contrat['numero_contrat'],
                        $dto->contratPdfFile
                    );
                }

                // 2. Mise à jour en BDD
                $this->contratService->updateContrat(
                    $agencyCode,
                    $id,
                    $dto,
                    $pdfPath
                );

                $this->addFlash('success', sprintf(
                    'Contrat n°%d mis à jour avec succès.%s',
                    $contrat['numero_contrat'],
                    $pdfPath ? ' PDF uploadé.' : ''
                ));

                return $this->redirectToRoute('app_contrat_entretien_show', [
                    'agencyCode' => $agencyCode,
                    'id'         => $id,
                ]);

            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur lors de la mise à jour : ' . $e->getMessage());
            }
        }

        return $this->render('contrat_entretien/edit.html.twig', [
            'agencyCode' => $agencyCode,
            'agency'     => $agency,
            'contrat'    => $contrat,
            'form'       => $form->createView(),
            'clientInfo' => $clientInfo,
            'dto'        => $dto,
        ]);
    }

    // =========================================================================
    //  DEACTIVATE — Résiliation (soft-delete)
    // =========================================================================

    /**
     * Désactive (résilie) un contrat d'entretien.
     *
     * URL  : /contrats/{agencyCode}/{id}/deactivate
     * POST : motif_resiliation (optionnel)
     */
    #[Route('/{agencyCode}/{id}/deactivate', name: 'app_contrat_entretien_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(string $agencyCode, int $id, Request $request): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat) {
            throw $this->createNotFoundException("Contrat #{$id} introuvable.");
        }

        // Vérification Voter
        $this->denyAccessUnlessGranted(ContratEntretienVoter::DEACTIVATE, $agencyCode);

        // Vérification CSRF
        if (!$this->isCsrfTokenValid('deactivate-contrat-' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $id,
            ]);
        }

        $motif = $request->request->get('motif_resiliation', '');

        if ($this->contratService->deactivateContrat($agencyCode, $id, $motif)) {
            $this->addFlash('success', "Le contrat n°{$contrat['numero_contrat']} a été résilié.");
        } else {
            $this->addFlash('danger', 'Erreur lors de la résiliation du contrat.');
        }

        return $this->redirectToRoute('app_contrat_entretien_show', [
            'agencyCode' => $agencyCode,
            'id'         => $id,
        ]);
    }

    // =========================================================================
    //  DELETE — Suppression définitive (hard-delete)
    // =========================================================================

    /**
     * Supprime définitivement un contrat et ses avenants.
     *
     * URL  : /contrats/{agencyCode}/{id}/delete
     * Réservé ROLE_ADMIN + ROLE_ADMIN_AGENCE (via Voter).
     */
    #[Route('/{agencyCode}/{id}/delete', name: 'app_contrat_entretien_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $agencyCode, int $id, Request $request): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat) {
            throw $this->createNotFoundException("Contrat #{$id} introuvable.");
        }

        // Vérification Voter (hard delete = ADMIN / ADMIN_AGENCE uniquement)
        $this->denyAccessUnlessGranted(ContratEntretienVoter::DELETE, $agencyCode);

        // Vérification CSRF
        if (!$this->isCsrfTokenValid('delete-contrat-' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $id,
            ]);
        }

        try {
            $this->contratService->deleteContrat($agencyCode, $id);
            $this->addFlash('success', "Le contrat n°{$contrat['numero_contrat']} a été supprimé définitivement.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Erreur lors de la suppression : ' . $e->getMessage());
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $id,
            ]);
        }

        return $this->redirectToRoute('app_contrat_entretien_index', [
            'agencyCode' => $agencyCode,
        ]);
    }

    // =========================================================================
    //  API JSON — Pour recherche AJAX / autocomplete (futur)
    // =========================================================================

    /**
     * API JSON : liste des contrats (pour filtrage JS côté client).
     *
     * URL : /contrats/{agencyCode}/api/list
     * Query : ?statut=actif&search=mondial&page=1
     */
    #[Route('/{agencyCode}/api/list', name: 'app_contrat_entretien_api_list', methods: ['GET'])]
    public function apiList(string $agencyCode, Request $request): JsonResponse
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);
        $this->denyAccessUnlessGranted(ContratEntretienVoter::LIST, $agencyCode);

        $filters = [
            'statut' => $request->query->get('statut', ''),
            'search' => $request->query->get('search', ''),
            'sort'   => $request->query->get('sort', ''),
            'order'  => $request->query->get('order', 'DESC'),
        ];

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = $request->query->getInt('per_page', 20);

        $result = $this->contratService->getContratsPaginated($agencyCode, $filters, $page, $perPage);

        return $this->json([
            'contrats'    => $result['contrats'],
            'total'       => $result['total'],
            'pages'       => $result['pages'],
            'currentPage' => $page,
        ]);
    }

    /**
     * API JSON : statistiques équipements d'un contrat.
     *
     * URL : /contrats/{agencyCode}/{id}/api/equip-stats
     */
    #[Route('/{agencyCode}/{id}/api/equip-stats', name: 'app_contrat_entretien_api_equip_stats', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apiEquipStats(string $agencyCode, int $id): JsonResponse
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);
        $this->denyAccessUnlessGranted(ContratEntretienVoter::VIEW, $agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat || empty($contrat['id_contact'])) {
            return $this->json(['error' => 'Contrat introuvable ou sans client lié.'], 404);
        }

        $stats = $this->contratService->countEquipementsForContrat($agencyCode, $contrat['id_contact']);

        return $this->json($stats);
    }

    #[Route('/{agencyCode}/client/new', name: 'app_contrat_entretien_create_client', methods: ['GET', 'POST'])]
    public function createClient(
        string $agencyCode,
        Request $request,
    ): Response {
        $agencyCode = strtoupper($agencyCode);
        
        $this->denyAccessUnlessGranted(ContratEntretienVoter::CREATE, $agencyCode);
        
        $dto = new ContactDTO();
        $form = $this->createForm(ContactType::class, $dto);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newId = $this->contactService->insertContact($agencyCode, $dto);
                
                // === Phase 2.3 — Sync immédiat vers Kizeo ===
                $syncResult = $this->kizeoClientSync->syncNewClient($agencyCode, $newId);
                
                if (!$syncResult['success'] && $syncResult['error']) {
                    // Afficher l'erreur (collision, API down, etc.)
                    // Non bloquant pour la création en BDD (le client est déjà inséré)
                    $this->addFlash('warning', $syncResult['error']);
                }
                // === Fin Phase 2.3 ===
                
                $action = $request->request->get('action', 'save_and_list');
                
                $this->addFlash('success', sprintf(
                    'Client "%s" créé avec succès sur l\'agence %s (ID: %d).%s',
                    $dto->raisonSociale,
                    $agencyCode,
                    $newId,
                    $syncResult['success'] ? ' Synchronisé sur Kizeo.' : ''
                ));
                
                if ($action === 'save_and_contrat') {
                    return $this->redirectToRoute('app_contrat_entretien_create', [
                        'agencyCode' => $agencyCode,
                        'contactId' => $newId,          // ID BDD (int)
                        'idContact' => $dto->idContact, // ID métier (string)
                    ]);
                }
                
                return $this->redirectToRoute('app_contrat_entretien_index', [
                    'agencyCode' => $agencyCode,
                ]);
                
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }
        
        $agency = $this->contratService->getAgencyByCode($agencyCode);
        
        return $this->render('contrat_entretien/create_client.html.twig', [
            'form'       => $form->createView(),
            'agencyCode' => $agencyCode,
            'agencyName' => $agency ? $agency['nom'] : null,
        ]);
    }

    // =========================================================================
    //  DOWNLOAD PDF — Téléchargement du PDF contrat
    // =========================================================================

    /**
     * Télécharge le PDF du contrat.
     *
     * URL : /contrats/{agencyCode}/{id}/pdf
     */
    #[Route('/{agencyCode}/{id}/pdf', name: 'app_contrat_entretien_download_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadPdf(string $agencyCode, int $id): Response
    {
        $agencyCode = $this->resolveAgencyOrThrow($agencyCode);

        $contrat = $this->contratService->getContratById($agencyCode, $id);

        if (!$contrat) {
            throw $this->createNotFoundException("Contrat #{$id} introuvable.");
        }

        $this->denyAccessUnlessGranted(ContratEntretienVoter::VIEW, $agencyCode);

        if (empty($contrat['contrat_pdf_path'])) {
            throw $this->createNotFoundException("Aucun PDF associé à ce contrat.");
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/storage/' . $contrat['contrat_pdf_path'];

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException("Fichier PDF introuvable sur le serveur.");
        }

        return $this->file($filePath);
    }

    // =========================================================================
    //  Helper privé
    // =========================================================================

    /**
     * Normalise et valide le code agence, ou lance une 404.
     */
    private function resolveAgencyOrThrow(string $agencyCode): string
    {
        $normalized = $this->contratService->normalizeAgencyCode($agencyCode);

        if (!$this->contratService->isValidAgencyCode($normalized)) {
            throw $this->createNotFoundException("Agence '{$agencyCode}' inconnue.");
        }

        return $normalized;
    }
}