<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AgencyRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur CRUD des équipements client
 * 
 * Gère :
 * - Édition d'un équipement existant
 * - Création d'un nouvel équipement
 * - Soft delete (archivage) d'un équipement
 * 
 * Utilise Doctrine DBAL pour les requêtes dynamiques sur equipement_sXX
 * (même pattern que HomeController).
 * 
 * @author Alex - SOMAFI GROUP
 * @version 1.0 - Session 08/02/2026 - Phase A
 */
#[IsGranted('ROLE_USER')]
class EquipementController extends AbstractController
{
    /**
     * Mapping statut_equipement → etat_equipement (libellé complet)
     * Utilisé pour calculer automatiquement etat_equipement lors de la sauvegarde.
     */
    private const STATUT_LABELS = [
        'A' => 'Bon état de fonctionnement le jour de la visite',
        'B' => 'Travaux préventifs',
        'C' => 'Travaux curatifs',
        'D' => 'Équipement à l\'arrêt / Inaccessible le jour de la visite',
        'E' => 'Équipement à l\'arrêt le jour de la visite',
        'F' => 'Équipement mis à l\'arrêt lors de l\'intervention',
        'G' => 'Équipement non présent sur site',
    ];

    /**
     * Champs autorisés pour l'édition (whitelist sécurité)
     * Seuls ces champs peuvent être modifiés via le formulaire.
     */
    private const EDITABLE_FIELDS = [
        'numero_equipement_client',
        'libelle_equipement',
        'marque',
        'numero_serie',
        'mise_en_service',
        'mode_fonctionnement',
        'hauteur',
        'largeur',
        'longueur',
        'repere_site_client',
        'statut_equipement',
        'anomalies',
        'observations',
        'is_hors_contrat',
    ];

    /**
     * Options du select mode_fonctionnement
     */
    private const MODES_FONCTIONNEMENT = [
        '' => '— Non renseigné —',
        'automatique' => 'Automatique',
        'manuel' => 'Manuel',
        'motorisé' => 'Motorisé',
    ];

    public function __construct(
        private readonly AgencyRepository $agencyRepository,
        private readonly Connection $connection,
    ) {
    }

    // =========================================================================
    // ROUTES PUBLIQUES - CRUD
    // =========================================================================

    /**
     * Formulaire d'édition d'un équipement (GET + POST)
     * 
     * GET  → Affiche le formulaire pré-rempli
     * POST → Sauvegarde les modifications et redirige vers la page équipements
     */
    #[Route(
        '/agency/{agencyCode}/client/{idContact}/equipment/{equipId}/edit',
        name: 'app_equipment_edit',
        requirements: ['agencyCode' => 'S\d+', 'idContact' => '\d+', 'equipId' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(string $agencyCode, int $idContact, int $equipId, Request $request): Response
    {
        // Vérification accès + rôle
        $this->checkAgencyAccess($agencyCode);
        $this->denyAccessUnlessGrantedOneOf(['ROLE_EDIT', 'ROLE_ADMIN', 'ROLE_ADMIN_AGENCE']);

        $agency = $this->getAgencyOrFail($agencyCode);
        $tableName = $this->getEquipmentTable($agencyCode);

        // Récupérer l'équipement
        $equipment = $this->findEquipmentOrFail($tableName, $equipId, $idContact);

        // Traitement POST
        if ($request->isMethod('POST')) {
            $data = $this->extractFormData($request);
            
            // Auto-calculer etat_equipement depuis statut_equipement
            $data['etat_equipement'] = self::STATUT_LABELS[$data['statut_equipement'] ?? ''] ?? null;

            $this->updateEquipment($tableName, $equipId, $data);

            $this->addFlash('success', sprintf(
                'Équipement %s modifié avec succès',
                $equipment['numero_equipement']
            ));

            return $this->redirectToClientEquipments($agencyCode, $idContact, $equipment);
        }

        return $this->render('equipment/edit.html.twig', [
            'agency' => $agency,
            'agencyCode' => $agencyCode,
            'idContact' => $idContact,
            'equipment' => $equipment,
            'modes_fonctionnement' => self::MODES_FONCTIONNEMENT,
            'statut_labels' => self::STATUT_LABELS,
        ]);
    }

    /**
     * Soft delete (archivage) d'un équipement
     * 
     * POST uniquement — is_archive = 1
     * L'équipement reste en BDD pour l'historique.
     */
    #[Route(
        '/agency/{agencyCode}/client/{idContact}/equipment/{equipId}/delete',
        name: 'app_equipment_delete',
        requirements: ['agencyCode' => 'S\d+', 'idContact' => '\d+', 'equipId' => '\d+'],
        methods: ['POST']
    )]
    public function delete(string $agencyCode, int $idContact, int $equipId, Request $request): Response
    {
        // Vérification accès + rôle
        $this->checkAgencyAccess($agencyCode);
        $this->denyAccessUnlessGrantedOneOf(['ROLE_DELETE', 'ROLE_ADMIN', 'ROLE_ADMIN_AGENCE']);

        // Vérifier le token CSRF (intention générique pour la modale partagée)
        if (!$this->isCsrfTokenValid('equipment_delete', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide');
            return $this->redirectToRoute('app_client_equipments', [
                'agencyCode' => $agencyCode,
                'idContact' => $idContact,
            ]);
        }

        $tableName = $this->getEquipmentTable($agencyCode);
        $equipment = $this->findEquipmentOrFail($tableName, $equipId, $idContact);

        // Soft delete : is_archive = 1
        $this->connection->executeStatement(
            "UPDATE {$tableName} SET is_archive = 1 WHERE id = :id",
            ['id' => $equipId]
        );

        $this->addFlash('success', sprintf(
            'Équipement %s archivé avec succès',
            $equipment['numero_equipement']
        ));

        return $this->redirectToClientEquipments($agencyCode, $idContact, $equipment);
    }

    /**
     * Formulaire de création d'un nouvel équipement (GET + POST)
     * 
     * GET  → Affiche le formulaire vide avec valeurs par défaut
     * POST → Crée l'équipement et redirige vers la page équipements
     */
    #[Route(
        '/agency/{agencyCode}/client/{idContact}/equipment/new',
        name: 'app_equipment_new',
        requirements: ['agencyCode' => 'S\d+', 'idContact' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function new(string $agencyCode, int $idContact, Request $request): Response
    {
        // Vérification accès + rôle
        $this->checkAgencyAccess($agencyCode);
        $this->denyAccessUnlessGrantedOneOf(['ROLE_EDIT', 'ROLE_ADMIN', 'ROLE_ADMIN_AGENCE']);

        $agency = $this->getAgencyOrFail($agencyCode);
        $tableName = $this->getEquipmentTable($agencyCode);

        // Récupérer l'année/visite depuis les query params (pré-remplissage)
        $defaultAnnee = $request->query->get('annee', date('Y'));
        $defaultVisite = $request->query->get('visite', 'CE1');

        // Récupérer le nom du client depuis contact_sXX
        $clientName = $this->getClientName($agencyCode, $idContact);

        // Traitement POST
        if ($request->isMethod('POST')) {
            $data = $this->extractFormData($request);

            // Champs obligatoires supplémentaires pour la création
            $numeroEquipement = trim($request->request->get('numero_equipement', ''));
            $annee = trim($request->request->get('annee', $defaultAnnee));
            $visite = trim($request->request->get('visite', $defaultVisite));

            // Validation du numéro d'équipement
            if (empty($numeroEquipement)) {
                $this->addFlash('error', 'Le numéro d\'équipement est obligatoire');
                return $this->render('equipment/new.html.twig', [
                    'agency' => $agency,
                    'agencyCode' => $agencyCode,
                    'idContact' => $idContact,
                    'clientName' => $clientName,
                    'default_annee' => $annee,
                    'default_visite' => $visite,
                    'form_data' => $request->request->all(),
                    'modes_fonctionnement' => self::MODES_FONCTIONNEMENT,
                    'statut_labels' => self::STATUT_LABELS,
                ]);
            }

            // Vérifier l'unicité du numéro pour ce client/année/visite
            if ($this->equipmentNumberExists($tableName, $idContact, $numeroEquipement, $annee, $visite)) {
                $this->addFlash('error', sprintf(
                    'Le numéro d\'équipement %s existe déjà pour cette visite (%s %s)',
                    $numeroEquipement, $visite, $annee
                ));
                return $this->render('equipment/new.html.twig', [
                    'agency' => $agency,
                    'agencyCode' => $agencyCode,
                    'idContact' => $idContact,
                    'clientName' => $clientName,
                    'default_annee' => $annee,
                    'default_visite' => $visite,
                    'form_data' => $request->request->all(),
                    'modes_fonctionnement' => self::MODES_FONCTIONNEMENT,
                    'statut_labels' => self::STATUT_LABELS,
                ]);
            }

            // Auto-calculer etat_equipement
            $data['etat_equipement'] = self::STATUT_LABELS[$data['statut_equipement'] ?? ''] ?? null;

            // Construire les données complètes pour l'INSERT
            $insertData = array_merge($data, [
                'numero_equipement' => $numeroEquipement,
                'id_contact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
                'is_archive' => 0,
                'is_hors_contrat' => (int) ($data['is_hors_contrat'] ?? 0),
                'date_enregistrement' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            $this->connection->insert($tableName, $insertData);

            $this->addFlash('success', sprintf(
                'Équipement %s créé avec succès',
                $numeroEquipement
            ));

            return $this->redirectToRoute('app_client_equipments', [
                'agencyCode' => $agencyCode,
                'idContact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
            ]);
        }

        return $this->render('equipment/new.html.twig', [
            'agency' => $agency,
            'agencyCode' => $agencyCode,
            'idContact' => $idContact,
            'clientName' => $clientName,
            'default_annee' => $defaultAnnee,
            'default_visite' => $defaultVisite,
            'form_data' => [],
            'modes_fonctionnement' => self::MODES_FONCTIONNEMENT,
            'statut_labels' => self::STATUT_LABELS,
        ]);
    }

    // =========================================================================
    // MÉTHODES PRIVÉES - Sécurité & Accès
    // =========================================================================

    /**
     * Vérifie que l'utilisateur a accès à l'agence demandée
     */
    private function checkAgencyAccess(string $agencyCode): void
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Accès non autorisé à cette agence');
        }
    }

    /**
     * Vérifie que l'utilisateur possède au moins un des rôles demandés
     * 
     * ROLE_ADMIN et ROLE_ADMIN_AGENCE incluent implicitement ROLE_EDIT et ROLE_DELETE.
     */
    private function denyAccessUnlessGrantedOneOf(array $roles): void
    {
        foreach ($roles as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }

        throw $this->createAccessDeniedException(
            'Vous n\'avez pas les droits nécessaires pour effectuer cette action'
        );
    }

    /**
     * Récupère l'agence ou lance une 404
     */
    private function getAgencyOrFail(string $agencyCode): object
    {
        $agency = $this->agencyRepository->findOneBy(['code' => $agencyCode, 'isActive' => true]);

        if (!$agency) {
            throw $this->createNotFoundException(sprintf('Agence %s non trouvée', $agencyCode));
        }

        return $agency;
    }

    // =========================================================================
    // MÉTHODES PRIVÉES - Accès BDD
    // =========================================================================

    /**
     * Récupère un équipement par son ID, ou lance une 404
     */
    private function findEquipmentOrFail(string $tableName, int $equipId, int $idContact): array
    {
        $sql = "SELECT * FROM {$tableName} WHERE id = :id AND id_contact = :idContact AND is_archive = 0 LIMIT 1";
        
        $equipment = $this->connection->fetchAssociative($sql, [
            'id' => $equipId,
            'idContact' => $idContact,
        ]);

        if (!$equipment) {
            throw $this->createNotFoundException('Équipement non trouvé');
        }

        return $equipment;
    }

    /**
     * Met à jour un équipement en BDD (UPDATE)
     */
    private function updateEquipment(string $tableName, int $equipId, array $data): void
    {
        // Filtrer uniquement les champs autorisés + etat_equipement (auto-calculé)
        $allowedKeys = array_merge(self::EDITABLE_FIELDS, ['etat_equipement']);
        $filteredData = array_intersect_key($data, array_flip($allowedKeys));

        // Gérer le checkbox is_hors_contrat
        if (isset($filteredData['is_hors_contrat'])) {
            $filteredData['is_hors_contrat'] = (int) $filteredData['is_hors_contrat'];
        }

        if (empty($filteredData)) {
            return;
        }

        // Construire le SET dynamiquement
        $setParts = [];
        $params = ['id' => $equipId];

        foreach ($filteredData as $column => $value) {
            $setParts[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        $sql = sprintf(
            "UPDATE %s SET %s WHERE id = :id",
            $tableName,
            implode(', ', $setParts)
        );

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * Vérifie si un numéro d'équipement existe déjà pour un client/année/visite
     */
    private function equipmentNumberExists(string $tableName, int $idContact, string $numero, string $annee, string $visite): bool
    {
        $sql = "SELECT COUNT(*) FROM {$tableName} 
                WHERE id_contact = :idContact 
                  AND numero_equipement = :numero 
                  AND annee = :annee 
                  AND visite = :visite 
                  AND is_archive = 0";

        $count = $this->connection->fetchOne($sql, [
            'idContact' => $idContact,
            'numero' => $numero,
            'annee' => $annee,
            'visite' => $visite,
        ]);

        return (int) $count > 0;
    }

    /**
     * Récupère le nom du client depuis contact_sXX
     */
    private function getClientName(string $agencyCode, int $idContact): string
    {
        $tableNumber = $this->extractTableNumber($agencyCode);
        $tableName = 'contact_s' . $tableNumber;

        try {
            $sql = "SELECT raison_sociale FROM {$tableName} WHERE id_contact = :idContact LIMIT 1";
            $result = $this->connection->fetchOne($sql, ['idContact' => $idContact]);
            return $result ?: 'Client inconnu';
        } catch (\Exception $e) {
            return 'Client inconnu';
        }
    }

    // =========================================================================
    // MÉTHODES PRIVÉES - Helpers
    // =========================================================================

    /**
     * Extrait les données du formulaire POST (whitelist)
     * 
     * @return array<string, string|null>
     */
    private function extractFormData(Request $request): array
    {
        $data = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $value = $request->request->get($field);

            if ($field === 'is_hors_contrat') {
                // Checkbox : présent = 1, absent = 0
                $data[$field] = $request->request->has($field) ? 1 : 0;
            } else {
                // Trim + convertir les chaînes vides en null
                $data[$field] = ($value !== null && trim($value) !== '') ? trim($value) : null;
            }
        }

        return $data;
    }

    /**
     * Construit le nom de la table équipement dynamique
     */
    private function getEquipmentTable(string $agencyCode): string
    {
        return 'equipement_s' . $this->extractTableNumber($agencyCode);
    }

    /**
     * Extrait le numéro de table depuis le code agence (S100 -> 100)
     */
    private function extractTableNumber(string $agencyCode): string
    {
        return ltrim($agencyCode, 'Ss');
    }

    /**
     * Redirige vers la page équipements client avec les filtres année/visite
     */
    private function redirectToClientEquipments(string $agencyCode, int $idContact, array $equipment): Response
    {
        return $this->redirectToRoute('app_client_equipments', [
            'agencyCode' => $agencyCode,
            'idContact' => $idContact,
            'annee' => $equipment['annee'] ?? date('Y'),
            'visite' => $equipment['visite'] ?? 'CE1',
        ]);
    }
}