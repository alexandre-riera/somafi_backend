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
use App\DTO\EquipementBulkDTO;
use App\Service\EquipementBulkGeneratorService;
use App\Service\EquipementInsertService;
use App\Service\Kizeo\KizeoEquipmentSyncService;

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
        private readonly EquipementBulkGeneratorService $bulkGenerator,
        private readonly EquipementInsertService $equipementInsertService,
        private readonly KizeoEquipmentSyncService $kizeoEquipmentSync,
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
                /** @var \App\Entity\User|null $user */
                $user = $this->getUser();
                $userId = $user?->getId();
                
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
     * URL : /{agencyCode}/{id}/api/equip-stats
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

    // ============================================================================
    //  MÉTHODE 1 — Formulaire de saisie (choix mode + lignes)
    // ============================================================================

    #[Route(
        '/{agencyCode}/{contratId}/bulk-equipements',
        name: 'app_contrat_entretien_bulk_equipements',
        requirements: ['contratId' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function bulkEquipements(
        string $agencyCode,
        int $contratId,
        Request $request,
    ): Response {
        $agencyCode = strtoupper($agencyCode);
        $this->denyAccessUnlessGranted(ContratEntretienVoter::EDIT, $agencyCode);

        // Récupérer le contrat
        $contrat = $this->contratService->getContratById($agencyCode, $contratId);
        if (!$contrat) {
            throw $this->createNotFoundException('Contrat introuvable.');
        }

        // Récupérer les infos client
        $clientInfo = null;
        if (!empty($contrat['contact_id'])) {
            $clientInfo = $this->contactService->findById($agencyCode, (int) $contrat['contact_id']);
        }

        // id_contact métier (string, référence Kizeo)
        $idContact = $contrat['id_contact'] ?? '';
        $contactId = (int) ($contrat['contact_id'] ?? 0);
        $nombreVisitesContrat = (int) ($contrat['nombre_visite'] ?? 1);

        // Compter les équipements existants
        $existingCount = $this->equipementInsertService->countExistingEquipements(
            $agencyCode,
            $idContact,
            date('Y')
        );

        // Si POST → générer et passer à la prévisualisation
        if ($request->isMethod('POST')) {
            $formData = $request->request->all('bulk');

            // Injecter les données du contrat dans le form data
            $formData['contact_id'] = $contactId;
            $formData['id_contact'] = $idContact;
            $formData['contrat_id'] = $contratId;
            $formData['annee'] = $formData['annee'] ?? date('Y');

            $dto = EquipementBulkDTO::fromRequest($formData, $agencyCode);

            // Validation
            $errors = $dto->validate();
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
                return $this->redirectToRoute('app_contrat_entretien_bulk_equipements', [
                    'agencyCode' => $agencyCode,
                    'contratId'  => $contratId,
                ]);
            }

            // Génération en mémoire
            $nombreVisitesContrat = (int) ($contrat['nombre_visite'] ?? 1);
            $result = $this->bulkGenerator->generate($dto, $nombreVisitesContrat);

            // Afficher les warnings
            foreach ($result['warnings'] as $warning) {
                $this->addFlash('warning', $warning);
            }

            if (empty($result['lines'])) {
                $this->addFlash('danger', 'Aucune ligne générée. Vérifiez vos saisies.');
                return $this->redirectToRoute('app_contrat_entretien_bulk_equipements', [
                    'agencyCode' => $agencyCode,
                    'contratId'  => $contratId,
                ]);
            }

            // Vérification des doublons
            $dedupResult = $this->equipementInsertService->checkDuplicates(
                $agencyCode,
                $idContact,
                $dto->annee,
                $result['lines']
            );

            if (!empty($dedupResult['duplicates'])) {
                $this->addFlash('warning', sprintf(
                    '%d doublon(s) détecté(s) — ces lignes ont été retirées de la prévisualisation.',
                    count($dedupResult['duplicates'])
                ));
            }

            // Stocker en session pour la prévisualisation
            $session = $request->getSession();
            $session->set('bulk_preview_lines', $dedupResult['clean']);
            $session->set('bulk_preview_duplicates', $dedupResult['duplicates']);
            $session->set('bulk_preview_stats', $result['stats']);
            $session->set('bulk_preview_agency', $agencyCode);
            $session->set('bulk_preview_contrat_id', $contratId);
            $session->set('bulk_preview_id_contact', $idContact);
            $session->set('bulk_preview_contact_id', $contactId);
            $session->set('bulk_preview_annee', $dto->annee);
            $session->set('bulk_preview_client_nom', $clientInfo['nom_contact'] ?? $clientInfo['raison_sociale'] ?? '');
            $session->set('bulk_preview_client_cp', $clientInfo['code_postal'] ?? '');
            $session->set('bulk_preview_client_ville', $clientInfo['ville'] ?? '');

            return $this->redirectToRoute('app_contrat_entretien_preview_equipements', [
                'agencyCode' => $agencyCode,
                'contratId'  => $contratId,
            ]);
        }

        return $this->render('contrat_entretien/bulk_equipements.html.twig', [
            'agencyCode'    => $agencyCode,
            'contrat'       => $contrat,
            'clientInfo'    => $clientInfo,
            'contratId'     => $contratId,
            'idContact'     => $idContact,
            'contactId'     => $contactId,
            'existingCount' => $existingCount,
            'annee'         => date('Y'),
            'nombreVisitesContrat'  => $nombreVisitesContrat,                          // ← AJOUTER
            'typePrefixes'          => EquipementBulkGeneratorService::getTypePrefixes()
        ]);
    }

    // ============================================================================
    //  MÉTHODE 2 — Prévisualisation du tableau éditable
    // ============================================================================

    #[Route(
        '/{agencyCode}/{contratId}/preview-equipements',
        name: 'app_contrat_entretien_preview_equipements',
        requirements: ['contratId' => '\d+'],
        methods: ['GET'],
    )]
    public function previewEquipements(
        string $agencyCode,
        int $contratId,
        Request $request,
    ): Response {
        $agencyCode = strtoupper($agencyCode);
        $this->denyAccessUnlessGranted(ContratEntretienVoter::EDIT, $agencyCode);

        $session = $request->getSession();

        // Vérifier que les données de preview existent en session
        $lines = $session->get('bulk_preview_lines', []);
        if (empty($lines)) {
            $this->addFlash('danger', 'Aucune donnée de prévisualisation. Veuillez recommencer la saisie.');
            return $this->redirectToRoute('app_contrat_entretien_bulk_equipements', [
                'agencyCode' => $agencyCode,
                'contratId'  => $contratId,
            ]);
        }

        // Vérifier la cohérence agence + contrat
        if ($session->get('bulk_preview_agency') !== $agencyCode
            || $session->get('bulk_preview_contrat_id') !== $contratId) {
            $this->addFlash('danger', 'Incohérence de session. Veuillez recommencer.');
            return $this->redirectToRoute('app_contrat_entretien_bulk_equipements', [
                'agencyCode' => $agencyCode,
                'contratId'  => $contratId,
            ]);
        }

        $contrat = $this->contratService->getContratById($agencyCode, $contratId);

        // Récupérer les infos client depuis la session (pas de re-query BDD)
        $clientNom = $session->get('bulk_preview_client_nom', '');
        $clientCp = $session->get('bulk_preview_client_cp', '');
        $clientVille = $session->get('bulk_preview_client_ville', '');

        // Trier les lignes par visite puis par numéro
        usort($lines, function ($a, $b) {
            $visitOrder = ['CE1' => 1, 'CE2' => 2, 'CE3' => 3, 'CE4' => 4, 'CEA' => 5];
            $va = $visitOrder[$a['visite']] ?? 9;
            $vb = $visitOrder[$b['visite']] ?? 9;
            if ($va !== $vb) return $va - $vb;
            return strnatcmp($a['numero_equipement'], $b['numero_equipement']);
        });

        $stats = $session->get('bulk_preview_stats', []);
        $duplicates = $session->get('bulk_preview_duplicates', []);

        return $this->render('contrat_entretien/preview_equipements.html.twig', [
            'agencyCode'  => $agencyCode,
            'contratId'   => $contratId,
            'contrat'     => $contrat,
            'clientNom'   => $clientNom,
            'clientCp'    => $clientCp,
            'clientVille' => $clientVille,
            'lines'       => $lines,
            'stats'       => $stats,
            'duplicates'  => $duplicates,
            'annee'       => $session->get('bulk_preview_annee', date('Y')),
        ]);
    }

    // ============================================================================
    //  MÉTHODE 3 — Confirmation et insertion en BDD
    // ============================================================================

    #[Route(
        '/{agencyCode}/{contratId}/insert-equipements',
        name: 'app_contrat_entretien_insert_equipements',
        requirements: ['contratId' => '\d+'],
        methods: ['POST'],
    )]
    public function insertEquipements(
        string $agencyCode,
        int $contratId,
        Request $request,
    ): Response {
        $agencyCode = strtoupper($agencyCode);
        $this->denyAccessUnlessGranted(ContratEntretienVoter::EDIT, $agencyCode);

        // Vérification CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('insert_equipements_' . $contratId, $token)) {
            $this->addFlash('danger', 'Token CSRF invalide. Veuillez réessayer.');
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $contratId,
            ]);
        }

        $session = $request->getSession();

        // Récupérer les lignes éditées depuis le POST (le JS envoie le tableau modifié)
        $submittedLines = $request->request->all('lines');

        // Si pas de lignes POST, fallback sur la session
        if (empty($submittedLines)) {
            $submittedLines = $session->get('bulk_preview_lines', []);
        }

        if (empty($submittedLines)) {
            $this->addFlash('danger', 'Aucune ligne à insérer.');
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $contratId,
            ]);
        }

        // Normaliser les lignes soumises
        $linesToInsert = [];
        foreach ($submittedLines as $line) {
            $linesToInsert[] = [
                'id_contact'          => (int) ($line['id_contact'] ?? $session->get('bulk_preview_id_contact', 0)),
                'numero_equipement'   => trim($line['numero_equipement'] ?? ''),
                'libelle_equipement'  => trim($line['libelle_equipement'] ?? ''),
                'visite'              => trim($line['visite'] ?? 'CEA'),
                'annee'               => trim($line['annee'] ?? date('Y')),
                'marque'              => trim($line['marque'] ?? ''),
                'mode_fonctionnement' => trim($line['mode_fonctionnement'] ?? ''),
                'repere_site_client'  => trim($line['repere_site_client'] ?? ''),
                'is_hors_contrat'     => (int) ($line['is_hors_contrat'] ?? 0),
                'is_archive'          => 0,
            ];
        }

        // Filtrer les lignes vides (numéro vide)
        $linesToInsert = array_filter($linesToInsert, fn($l) => !empty($l['numero_equipement']));

        if (empty($linesToInsert)) {
            $this->addFlash('danger', 'Toutes les lignes sont vides après filtrage.');
            return $this->redirectToRoute('app_contrat_entretien_show', [
                'agencyCode' => $agencyCode,
                'id'         => $contratId,
            ]);
        }

        // Insertion batch
        $result = $this->equipementInsertService->insertBatch($agencyCode, array_values($linesToInsert));

        // Nettoyer la session
        $session->remove('bulk_preview_lines');
        $session->remove('bulk_preview_duplicates');
        $session->remove('bulk_preview_stats');
        $session->remove('bulk_preview_agency');
        $session->remove('bulk_preview_contrat_id');
        $session->remove('bulk_preview_id_contact');
        $session->remove('bulk_preview_contact_id');
        $session->remove('bulk_preview_annee');
        $session->remove('bulk_preview_client_nom');
        $session->remove('bulk_preview_client_cp');
        $session->remove('bulk_preview_client_ville');

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('danger', $error);
            }
        }

        if ($result['inserted'] > 0) {
            $this->addFlash('success', sprintf(
                '%d équipement(s) créé(s) avec succès en BDD.',
                $result['inserted']
            ));

            // Phase 3.7 — Sync immédiat vers Kizeo
            $syncResult = $this->kizeoEquipmentSync->syncForAgency($agencyCode);

            if ($syncResult['success']) {
                $s = $syncResult['stats'];
                $this->addFlash('info', sprintf(
                    'Liste Kizeo synchronisée : %d ajoutés, %d mis à jour, %d conservés, %d supprimés — %d items total.',
                    $s['ajoutes'],
                    $s['mis_a_jour'],
                    $s['conserves'],
                    $s['supprimes'],
                    $s['total_envoyes']
                ));
            } else {
                // Non bloquant : les équipements sont en BDD, le sync CRON rattrapera
                $this->addFlash('warning', sprintf(
                    'Équipements insérés en BDD mais sync Kizeo échouée : %s. Le CRON rattrapera.',
                    $syncResult['error'] ?? 'erreur inconnue'
                ));
            }
        } else {
            $this->addFlash('warning', 'Aucun équipement inséré.');
        }

        return $this->redirectToRoute('app_contrat_entretien_show', [
            'agencyCode' => $agencyCode,
            'id'         => $contratId,
        ]);
    }
}