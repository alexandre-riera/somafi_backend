<?php

namespace App\Service\Kizeo;

use App\Repository\AgencyRepository;
use App\Service\Equipment\EquipmentFactory;
use App\Service\Equipment\EquipmentDeduplicator;
use App\Service\Equipment\OffContractNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service principal de traitement des formulaires Kizeo
 * 
 * Responsabilités:
 * - Récupère les formulaires non lus via KizeoApiService
 * - Parse le JSON et extrait les équipements (contrat + hors contrat)
 * - Déduplique avant enregistrement
 * - Marque les formulaires comme lus
 */
class KizeoFormProcessor
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly EquipmentDeduplicator $deduplicator,
        private readonly OffContractNumberGenerator $numberGenerator,
        private readonly AgencyRepository $agencyRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $kizeoLogger,
        private readonly array $kizeoFormIds,
    ) {
    }

    /**
     * Traite toutes les agences configurées
     * 
     * @return array<string, array<string, mixed>>
     */
    public function processAllAgencies(int $limit = 50, bool $dryRun = false): array
    {
        $results = [];
        $agencies = $this->agencyRepository->findWithKizeoForm();

        foreach ($agencies as $agency) {
            $results[$agency->getCode()] = $this->processAgency($agency->getCode(), $limit, $dryRun);
        }

        return $results;
    }

    /**
     * Traite une agence spécifique
     * 
     * @return array<string, mixed>
     */
    public function processAgency(string $agencyCode, int $limit = 50, bool $dryRun = false): array
    {
        $result = [
            'success' => true,
            'forms_processed' => 0,
            'contract_created' => 0,
            'contract_updated' => 0,
            'offcontract_created' => 0,
            'offcontract_skipped' => 0,
            'photos_saved' => 0,
            'errors' => 0,
        ];

        // Récupérer le form_id Kizeo pour cette agence
        $formId = $this->kizeoFormIds[$agencyCode] ?? null;
        
        if (!$formId) {
            $this->kizeoLogger->warning('Pas de form_id configuré pour l\'agence', [
                'agency' => $agencyCode,
            ]);
            $result['success'] = false;
            $result['error'] = 'Pas de form_id Kizeo configuré';
            return $result;
        }

        $this->kizeoLogger->info('Début traitement agence', [
            'agency' => $agencyCode,
            'form_id' => $formId,
        ]);

        // Récupérer les formulaires non lus
        $forms = $this->kizeoApi->getUnreadForms($formId, $limit);

        if (empty($forms)) {
            $this->kizeoLogger->info('Aucun formulaire à traiter', ['agency' => $agencyCode]);
            return $result;
        }

        foreach ($forms as $formData) {
            try {
                $formResult = $this->processForm($agencyCode, $formId, $formData, $dryRun);
                
                $result['forms_processed']++;
                $result['contract_created'] += $formResult['contract_created'];
                $result['contract_updated'] += $formResult['contract_updated'];
                $result['offcontract_created'] += $formResult['offcontract_created'];
                $result['offcontract_skipped'] += $formResult['offcontract_skipped'];
                $result['photos_saved'] += $formResult['photos_saved'];

            } catch (\Exception $e) {
                $result['errors']++;
                $this->kizeoLogger->error('Erreur traitement formulaire', [
                    'agency' => $agencyCode,
                    'data_id' => $formData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Traite un formulaire individuel
     * 
     * @param array<string, mixed> $formData
     * @return array<string, int>
     */
    private function processForm(string $agencyCode, int $formId, array $formData, bool $dryRun): array
    {
        $result = [
            'contract_created' => 0,
            'contract_updated' => 0,
            'offcontract_created' => 0,
            'offcontract_skipped' => 0,
            'photos_saved' => 0,
        ];

        $dataId = $formData['id'];
        $fields = $formData['fields'] ?? [];

        // Extraire les informations communes
        $idContact = $this->extractFieldValue($fields, 'id_client_');
        $dateVisite = $this->extractFieldValue($fields, 'date_et_heure1');
        $trigramme = $this->extractFieldValue($fields, 'trigramme');

        if (!$idContact) {
            $this->kizeoLogger->warning('Formulaire sans id_contact', [
                'data_id' => $dataId,
            ]);
            return $result;
        }

        // 1. Traiter les équipements AU CONTRAT
        $contractEquipments = $fields['contrat_de_maintenance']['value'] ?? [];
        foreach ($contractEquipments as $equipData) {
            $contractResult = $this->processContractEquipment(
                $agencyCode,
                $formId,
                $dataId,
                $idContact,
                $dateVisite,
                $trigramme,
                $equipData,
                $dryRun
            );
            $result['contract_created'] += $contractResult['created'];
            $result['contract_updated'] += $contractResult['updated'];
        }

        // 2. Traiter les équipements HORS CONTRAT
        $offContractEquipments = $fields['tableau2']['value'] ?? [];
        foreach ($offContractEquipments as $index => $equipData) {
            $offContractResult = $this->processOffContractEquipment(
                $agencyCode,
                $formId,
                $dataId,
                $index,
                $idContact,
                $dateVisite,
                $trigramme,
                $equipData,
                $dryRun
            );
            $result['offcontract_created'] += $offContractResult['created'];
            $result['offcontract_skipped'] += $offContractResult['skipped'];
        }

        // 3. Marquer le formulaire comme lu (CRITIQUE pour éviter les doublons)
        if (!$dryRun) {
            $this->kizeoApi->markAsRead($formId, $dataId);
        }

        return $result;
    }

    /**
     * Extrait la valeur d'un champ de l'équipement
     * Gère les différentes structures de données Kizeo (value, valuesAsArray, etc.)
     * 
     * @param array<string, mixed> $equipData Données de l'équipement depuis le subform
     * @param string $fieldName Nom du champ à extraire
     * @return string|array|null La valeur extraite ou null si vide/inexistant
     */
    private function getEquipDataValue(array $equipData, string $fieldName): string|array|null
    {
        if (!isset($equipData[$fieldName])) {
            return null;
        }
        
        $field = $equipData[$fieldName];
        
        // Si c'est directement une valeur string
        if (is_string($field)) {
            return trim($field) !== '' ? trim($field) : null;
        }
        
        // Si c'est un array avec 'value'
        if (is_array($field) && array_key_exists('value', $field)) {
            $value = $field['value'];
            
            // Pour les selects multiples, retourner valuesAsArray si disponible
            if (!empty($field['valuesAsArray']) && is_array($field['valuesAsArray'])) {
                $filtered = array_filter($field['valuesAsArray'], fn($v) => !empty(trim((string)$v)));
                if (!empty($filtered)) {
                    return $filtered;
                }
            }
            
            // Valeur simple
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            
            // Array vide ou null
            if (is_array($value) && empty($value)) {
                return null;
            }
        }
        
        return null;
    }

    /**
     * Extrait toutes les anomalies d'un équipement
     * Les anomalies sont réparties dans plusieurs champs selon le type d'équipement
     * 
     * @param array<string, mixed> $equipData Données de l'équipement
     * @return string|null Anomalies concaténées séparées par " | " ou null si aucune
     */
    private function extractAnomalies(array $equipData): ?string
    {
        // Liste de tous les champs d'anomalies possibles dans Kizeo
        $anomalieFields = [
            'anomalies_sec_',                   // Portes sectionnelles
            'anomalie_rapide',                  // Portes rapides  
            'anomalie_rid_vor',                 // Rideaux/Volets
            'anomalie_portail',                 // Portails
            'anomalie_niv_plq_mip_tel_blr_',    // Niveleurs/Plaques/MIP/Télescopiques/BLR
            'anomalie_sas',                     // SAS
            'anomalie_ble1',                    // Barrières levantes
            'anomalie_tou1',                    // Tourniquets
            'anomalie_ppv_cfe',                 // PPV/CFE
            'anomalie_sec_rid_rap_vor_pac',     // Combinaison SEC/RID/RAP/VOR/PAC
            'anomalie_portail_auto_moto',       // Portails auto/motorisés
            'anomalie_cfe_ppv_auto_moto',       // CFE/PPV auto/motorisés
            'anomalie_ble_moto_auto',           // Barrières motorisées/auto
            'anomalie_hydraulique',             // Équipements hydrauliques
            'autres_composants',                // Champ texte libre pour autres
            'information_autre_composant',      // Informations complémentaires
        ];

        $anomalies = [];
        
        foreach ($anomalieFields as $field) {
            $value = $this->getEquipDataValue($equipData, $field);
            
            if (!empty($value)) {
                // Si c'est un array (select multiple), concaténer les valeurs
                if (is_array($value)) {
                    $valueStr = implode(', ', array_filter($value));
                } else {
                    $valueStr = (string)$value;
                }
                
                if (!empty(trim($valueStr))) {
                    $anomalies[] = trim($valueStr);
                }
            }
        }
        
        return !empty($anomalies) ? implode(' | ', $anomalies) : null;
    }

    /**
     * Retourne la description textuelle du statut équipement
     * Basé sur les codes officiels SOMAFI (Margaux V5)
     * 
     * ÉQUIPEMENT SOUS CONTRAT:
     * - A: Bon état de fonctionnement le jour de la visite (VERT)
     * - B: Travaux préventifs (ORANGE)
     * - C: Travaux curatifs (ROUGE)
     * - D: Équipement inaccessible le jour de la visite (NOIR)
     * - E: Équipement à l'arrêt le jour de la visite (NOIR)
     * - F: Équipement mis à l'arrêt lors de l'intervention (NOIR)
     * - G: Équipement non présent sur site (NOIR)
     * 
     * ÉQUIPEMENT HORS CONTRAT:
     * - A: Bon état de fonctionnement (VERT)
     * - B: Travaux préventifs (ORANGE)
     * - C: Travaux curatifs (ROUGE)
     * - D: Équipement à l'arrêt le jour de la visite (NOIR)
     * - E: Équipement mis à l'arrêt lors de l'intervention (NOIR)
     * 
     * @param string|null $statusCode Code lettre (A, B, C, D, E, F, G)
     * @param bool $isHorsContrat True si équipement hors contrat
     * @return string|null Description textuelle du statut
     */
    private function getStatusDescription(?string $statusCode, bool $isHorsContrat = false): ?string
    {
        if (empty($statusCode)) {
            return null;
        }
        
        // Descriptions pour équipements SOUS CONTRAT
        $contractDescriptions = [
            'A' => 'Bon état de fonctionnement le jour de la visite',
            'B' => 'Travaux préventifs',
            'C' => 'Travaux curatifs',
            'D' => 'Équipement inaccessible le jour de la visite',
            'E' => 'Équipement à l\'arrêt le jour de la visite',
            'F' => 'Équipement mis à l\'arrêt lors de l\'intervention',
            'G' => 'Équipement non présent sur site',
        ];
        
        // Descriptions pour équipements HORS CONTRAT (codes D et E différents)
        $offContractDescriptions = [
            'A' => 'Bon état de fonctionnement le jour de la visite',
            'B' => 'Travaux préventifs',
            'C' => 'Travaux curatifs',
            'D' => 'Équipement à l\'arrêt le jour de la visite',
            'E' => 'Équipement mis à l\'arrêt lors de l\'intervention',
        ];
        
        $descriptions = $isHorsContrat ? $offContractDescriptions : $contractDescriptions;
        
        return $descriptions[strtoupper($statusCode)] ?? null;
    }

    /**
     * Retourne la couleur associée au statut équipement
     * Utile pour l'affichage dans les PDF et tableaux
     * 
     * @param string|null $statusCode Code lettre (A, B, C, D, E, F, G)
     * @param bool $isHorsContrat True si équipement hors contrat
     * @return string Couleur (vert, orange, rouge, noir)
     */
    private function getStatusColor(?string $statusCode, bool $isHorsContrat = false): string
    {
        if (empty($statusCode)) {
            return 'noir'; // Défaut
        }
        
        $code = strtoupper($statusCode);
        
        // Couleurs communes
        if ($code === 'A') {
            return 'vert';      // Bon état
        }
        
        if ($code === 'B') {
            return 'orange';    // Travaux préventifs
        }
        
        if ($code === 'C') {
            return 'rouge';     // Travaux curatifs
        }
        
        // D, E, F, G = noir (états particuliers)
        return 'noir';
    }

    /**
     * Calcule le code statut basé sur les champs de calcul Kizeo
     * Utilisé en fallback si le champ 'etat' n'est pas rempli
     * 
     * Les champs calcul_* sont des flags 0/1 calculés automatiquement par Kizeo
     * en fonction des réponses du technicien
     * 
     * @param array<string, mixed> $equipData Données de l'équipement
     * @param bool $isHorsContrat True si équipement hors contrat
     * @return string|null Code statut (A, B, C, D, E, F, G)
     */
    private function calculateStatusFromFields(array $equipData, bool $isHorsContrat = false): ?string
    {
        // Priorité des états (du plus critique au moins critique)
        
        // Travaux curatifs (ROUGE) = C
        if ($this->getEquipDataValue($equipData, 'travaux_obligatoire') === '1') {
            return 'C';
        }
        
        // Travaux préventifs (ORANGE) = B
        if ($this->getEquipDataValue($equipData, 'calcul_travaux_a_prevoir') === '1') {
            return 'B';
        }
        
        // Bon état (VERT) = A
        if ($this->getEquipDataValue($equipData, 'calcul_rien_a_signaler') === '1') {
            return 'A';
        }
        
        // États particuliers (NOIR)
        if (!$isHorsContrat) {
            // Codes spécifiques SOUS CONTRAT
            if ($this->getEquipDataValue($equipData, 'equipement_inaccessible_le_jo') === '1') {
                return 'D'; // Inaccessible
            }
            if ($this->getEquipDataValue($equipData, 'equipement_a_l_arret_le_jour_') === '1') {
                return 'E'; // À l'arrêt
            }
            if ($this->getEquipDataValue($equipData, 'equipement_mis_a_l_arret_le_j') === '1') {
                return 'F'; // Mis à l'arrêt
            }
            if ($this->getEquipDataValue($equipData, 'equipement_non_present_sur_si') === '1') {
                return 'G'; // Non présent
            }
        } else {
            // Codes spécifiques HORS CONTRAT (pas de F et G)
            if ($this->getEquipDataValue($equipData, 'equipement_a_l_arret_le_jour_') === '1') {
                return 'D'; // À l'arrêt (= E sous contrat)
            }
            if ($this->getEquipDataValue($equipData, 'equipement_mis_a_l_arret_le_j') === '1') {
                return 'E'; // Mis à l'arrêt (= F sous contrat)
            }
        }
        
        // Fallback: utiliser le champ etat directement
        return $this->getEquipDataValue($equipData, 'etat');
    }

    /**
     * Construit les observations à partir des données de l'équipement
     * Inclut le temps de travail estimé et les besoins en nacelle
     * 
     * @param array<string, mixed> $equipData Données de l'équipement
     * @return string|null Observations formatées ou null si vide
     */
    private function buildObservations(array $equipData): ?string
    {
        $observations = [];
        
        // Temps de travail estimé pour les réparations
        $tempsEstime = $this->getEquipDataValue($equipData, 'temps_de_travail_estime_pour_');
        if (!empty($tempsEstime)) {
            $observations[] = "Temps estimé: {$tempsEstime}";
        }
        
        // Hauteur de nacelle nécessaire
        $nacelle = $this->getEquipDataValue($equipData, 'hauteur_de_nacelle_necessaire');
        if (!empty($nacelle)) {
            // Ne pas inclure si "Pas de nacelle"
            $nacelleNormalized = strtolower(trim($nacelle));
            if ($nacelleNormalized !== 'pas de nacelle' && $nacelleNormalized !== '') {
                $observations[] = "Nacelle: {$nacelle}";
            }
        }
        
        return !empty($observations) ? implode(' - ', $observations) : null;
    }


    /**
     * Traite un équipement AU CONTRAT avec mapping complet des champs
     * 
     * @param string $agencyCode Code agence (S40, S60, etc.)
     * @param int $formId ID du formulaire Kizeo
     * @param int $dataId ID de la soumission Kizeo
     * @param int $idContact ID du contact/client dans la BDD
     * @param string|null $dateVisite Date de la visite (format YYYY-MM-DD)
     * @param string|null $trigramme Trigramme du technicien
     * @param array<string, mixed> $equipData Données de l'équipement depuis contrat_de_maintenance
     * @param bool $dryRun Si true, ne persiste pas en BDD
     * @return array<string, int> ['created' => 0|1, 'updated' => 0|1]
     */
    private function processContractEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $idContact,
        ?string $dateVisite,
        ?string $trigramme,
        array $equipData,
        bool $dryRun
    ): array {
        $result = ['created' => 0, 'updated' => 0];

        // ==========================================================================
        // 1. EXTRACTION DU NUMÉRO D'ÉQUIPEMENT (OBLIGATOIRE)
        // ==========================================================================
        $numero = $this->getEquipDataValue($equipData, 'equipement');
        if (!$numero) {
            $this->kizeoLogger->debug('Équipement sans numéro, ignoré', [
                'form_id' => $formId,
                'data_id' => $dataId,
            ]);
            return $result;
        }

        // ==========================================================================
        // 2. EXTRACTION VISITE ET ANNÉE
        // ==========================================================================
        $visite = $this->extractVisiteFromPath($equipData['equipement']['path'] ?? '');
        $annee = $dateVisite ? (new \DateTime($dateVisite))->format('Y') : date('Y');

        // ==========================================================================
        // 3. DÉDUPLICATION
        // ==========================================================================
        $exists = $this->deduplicator->existsContractEquipment(
            $agencyCode,
            $idContact,
            $numero,
            $visite,
            $dateVisite ? new \DateTime($dateVisite) : null
        );

        if ($exists) {
            $this->kizeoLogger->debug('Équipement contrat ignoré (doublon)', [
                'agency' => $agencyCode,
                'numero' => $numero,
                'visite' => $visite,
                'annee' => $annee,
            ]);
            return $result;
        }

        // ==========================================================================
        // 4. CRÉATION DE L'ENTITÉ
        // ==========================================================================
        $entity = $this->equipmentFactory->createForAgency($agencyCode);
        
        // --- Champs obligatoires ---
        $entity->setIdContact($idContact);
        $entity->setNumeroEquipement($numero);
        $entity->setVisite($visite);
        $entity->setAnnee($annee);
        $entity->setDateDerniereVisite($dateVisite ? new \DateTime($dateVisite) : null);
        $entity->setTrigrammeTech($trigramme);
        $entity->setIsHorsContrat(false);
        $entity->setIsArchive(false);
        $entity->setKizeoFormId($formId);
        $entity->setKizeoDataId($dataId);
        $entity->setDateEnregistrement(new \DateTime());
        
        // ==========================================================================
        // 5. CHAMPS ENRICHIS (NOUVEAU MAPPING COMPLET)
        // ==========================================================================
        
        // Libellé équipement (type: Porte Rapide, Porte Sectionelle, Niveleur, etc.)
        // Champ Kizeo: reference7
        $entity->setLibelleEquipement(
            $this->getEquipDataValue($equipData, 'reference7') ?? ''
        );
        
        // Repère site client (localisation sur le site: Charcuterie, QUAI, Poissonnerie)
        // Champ Kizeo: localisation_site_client
        $entity->setRepereSiteClient(
            $this->getEquipDataValue($equipData, 'localisation_site_client')
        );
        
        // Année de mise en service
        // Champ Kizeo: reference2
        $entity->setMiseEnService(
            $this->getEquipDataValue($equipData, 'reference2')
        );
        
        // Numéro de série de l'équipement
        // Champ Kizeo: reference6
        $entity->setNumeroSerie(
            $this->getEquipDataValue($equipData, 'reference6')
        );
        
        // Marque du fabricant (DITEC, ALPHA DEUREN, MISCHLER SOPRECA, etc.)
        // Champ Kizeo: reference5
        $entity->setMarque(
            $this->getEquipDataValue($equipData, 'reference5')
        );
        
        // Mode de fonctionnement (automatique, manuel)
        // Champ Kizeo: mode_fonctionnement_2
        $entity->setModeFonctionnement(
            $this->getEquipDataValue($equipData, 'mode_fonctionnement_2')
        );
        
        // Dimensions
        // Champ Kizeo: reference3 = hauteur, reference1 = largeur
        $entity->setHauteur(
            $this->getEquipDataValue($equipData, 'reference3')
        );
        $entity->setLargeur(
            $this->getEquipDataValue($equipData, 'reference1')
        );
        
        // ==========================================================================
        // STATUT ÉQUIPEMENT (Codes officiels SOMAFI - Margaux V5)
        // ==========================================================================
        // A: Bon état (VERT) / B: Travaux préventifs (ORANGE) / C: Travaux curatifs (ROUGE)
        // D: Inaccessible (NOIR) / E: À l'arrêt (NOIR) / F: Mis à l'arrêt (NOIR) / G: Non présent (NOIR)
        
        // Récupérer le code lettre depuis Kizeo ou le calculer depuis les champs
        $statusCode = $this->getEquipDataValue($equipData, 'etat');
        if (empty($statusCode)) {
            // Fallback: calculer depuis les champs calcul_*
            $statusCode = $this->calculateStatusFromFields($equipData, false);
        }
        
        // statut_equipement = Code lettre (A, B, C, D, E, F, G)
        $entity->setStatutEquipement($statusCode);
        
        // etat_equipement = Description textuelle complète
        $entity->setEtatEquipement(
            $this->getStatusDescription($statusCode, false)
        );
        
        // Anomalies (combinaison de tous les champs d'anomalies non vides)
        $entity->setAnomalies(
            $this->extractAnomalies($equipData)
        );
        
        // Observations (temps de travail estimé + besoins nacelle)
        $entity->setObservations(
            $this->buildObservations($equipData)
        );

        // ==========================================================================
        // 6. PERSISTANCE
        // ==========================================================================
        if (!$dryRun) {
            $this->em->persist($entity);
            
            $this->kizeoLogger->info('Équipement contrat créé', [
                'agency' => $agencyCode,
                'numero' => $numero,
                'libelle' => $entity->getLibelleEquipement(),
                'marque' => $entity->getMarque(),
                'statut' => $entity->getStatutEquipement(),
            ]);
        }

        $result['created'] = 1;
        return $result;
    }

    // =============================================================================
    // MÉTHODE processOffContractEquipment() MODIFIÉE (ÉQUIPEMENTS HORS CONTRAT)
    // =============================================================================

    /**
     * Traite un équipement HORS CONTRAT avec mapping complet
     * Les équipements hors contrat viennent du tableau2 et utilisent une déduplication différente
     * 
     * @param string $agencyCode Code agence
     * @param int $formId ID du formulaire Kizeo
     * @param int $dataId ID de la soumission Kizeo
     * @param int $index Index dans le tableau (pour déduplication)
     * @param int $idContact ID du contact/client
     * @param string|null $dateVisite Date de la visite
     * @param string|null $trigramme Trigramme du technicien
     * @param array<string, mixed> $equipData Données de l'équipement depuis tableau2
     * @param bool $dryRun Si true, ne persiste pas en BDD
     * @return array<string, int> ['created' => 0|1, 'skipped' => 0|1]
     */
    private function processOffContractEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $index,
        int $idContact,
        ?string $dateVisite,
        ?string $trigramme,
        array $equipData,
        bool $dryRun
    ): array {
        $result = ['created' => 0, 'skipped' => 0];

        // ==========================================================================
        // 1. DÉDUPLICATION CRITIQUE: form_id + data_id + index
        // ==========================================================================
        $exists = $this->deduplicator->existsOffContractEquipment(
            $agencyCode,
            $formId,
            $dataId,
            $index
        );

        if ($exists) {
            $this->kizeoLogger->debug('Équipement HC ignoré (doublon)', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'index' => $index,
            ]);
            $result['skipped'] = 1;
            return $result;
        }

        // ==========================================================================
        // 2. EXTRACTION DES DONNÉES
        // ==========================================================================
        
        // Pour les équipements hors contrat, les noms de champs peuvent être différents
        // Utiliser les mêmes noms que contrat_de_maintenance si disponibles
        $libelle = $this->getEquipDataValue($equipData, 'reference7') 
            ?? $this->getEquipDataValue($equipData, 'libelle_produit_hc')
            ?? $this->getEquipDataValue($equipData, 'type_equipement')
            ?? 'Équipement HC';
            
        $annee = $dateVisite ? (new \DateTime($dateVisite))->format('Y') : date('Y');
        
        // Générer un numéro d'équipement basé sur le type
        $numero = $this->generateOffContractNumber($libelle, $index);

        // ==========================================================================
        // 3. CRÉATION DE L'ENTITÉ
        // ==========================================================================
        $entity = $this->equipmentFactory->createForAgency($agencyCode);
        
        // --- Champs obligatoires ---
        $entity->setIdContact($idContact);
        $entity->setNumeroEquipement($numero);
        $entity->setLibelleEquipement($libelle);
        $entity->setVisite('HC'); // Hors Contrat
        $entity->setAnnee($annee);
        $entity->setDateDerniereVisite($dateVisite ? new \DateTime($dateVisite) : null);
        $entity->setTrigrammeTech($trigramme);
        $entity->setIsHorsContrat(true);
        $entity->setIsArchive(false);
        $entity->setKizeoFormId($formId);
        $entity->setKizeoDataId($dataId);
        $entity->setKizeoIndex($index); // CRITIQUE pour déduplication
        $entity->setDateEnregistrement(new \DateTime());
        
        // --- Champs enrichis (même mapping que contrat) ---
        $entity->setRepereSiteClient(
            $this->getEquipDataValue($equipData, 'localisation_site_client')
        );
        
        $entity->setMiseEnService(
            $this->getEquipDataValue($equipData, 'reference2')
        );
        
        $entity->setNumeroSerie(
            $this->getEquipDataValue($equipData, 'reference6')
        );
        
        $entity->setMarque(
            $this->getEquipDataValue($equipData, 'reference5')
        );
        
        $entity->setModeFonctionnement(
            $this->getEquipDataValue($equipData, 'mode_fonctionnement_2')
        );
        
        $entity->setHauteur(
            $this->getEquipDataValue($equipData, 'reference3')
        );
        
        $entity->setLargeur(
            $this->getEquipDataValue($equipData, 'reference1')
        );
        
        // ==========================================================================
        // STATUT ÉQUIPEMENT HORS CONTRAT (Codes officiels SOMAFI - Margaux V5)
        // ==========================================================================
        // A: Bon état (VERT) / B: Travaux préventifs (ORANGE) / C: Travaux curatifs (ROUGE)
        // D: À l'arrêt (NOIR) / E: Mis à l'arrêt (NOIR)
        // Note: Pas de codes F et G pour les équipements hors contrat
        
        $statusCode = $this->getEquipDataValue($equipData, 'etat');
        if (empty($statusCode)) {
            $statusCode = $this->calculateStatusFromFields($equipData, true); // true = hors contrat
        }
        
        $entity->setStatutEquipement($statusCode);
        
        $entity->setEtatEquipement(
            $this->getStatusDescription($statusCode, true) // true = hors contrat
        );
        
        $entity->setAnomalies(
            $this->extractAnomalies($equipData)
        );
        
        $entity->setObservations(
            $this->buildObservations($equipData)
        );

        // ==========================================================================
        // 4. PERSISTANCE
        // ==========================================================================
        if (!$dryRun) {
            $this->em->persist($entity);
            
            $this->kizeoLogger->info('Équipement HC créé', [
                'agency' => $agencyCode,
                'numero' => $numero,
                'libelle' => $libelle,
                'index' => $index,
            ]);
        }

        $result['created'] = 1;
        return $result;
    }

    /**
     * Extrait une valeur d'un champ Kizeo
     * 
     * @param array<string, mixed> $fields
     */
    private function extractFieldValue(array $fields, string $fieldName): mixed
    {
        return $fields[$fieldName]['value'] ?? null;
    }

    /**
     * Extrait la visite depuis le path d'un équipement
     * Ex: "GROUPE MAURIN\\CE1" -> "CE1"
     */
    private function extractVisiteFromPath(string $path): string
    {
        if (empty($path)) {
            return 'CE1';
        }

        $parts = explode('\\', $path);
        $lastPart = end($parts);

        // Vérifier que c'est bien un code visite valide
        if (in_array($lastPart, ['CEA', 'CE1', 'CE2', 'CE3', 'CE4'])) {
            return $lastPart;
        }

        return 'CE1';
    }

    /**
     * Génère un numéro d'équipement pour les équipements hors contrat
     * Format: PREFIX + INDEX (ex: SEC01, RAP02, NIV01)
     * 
     * @param string $libelle Libellé de l'équipement
     * @param int $index Index dans le tableau
     * @return string Numéro généré
     */
    private function generateOffContractNumber(string $libelle, int $index): string
    {
        // Mapping libellé -> préfixe
        $prefixMap = [
            'porte rapide' => 'RAP',
            'porte sectionelle' => 'SEC',
            'porte sectionnelle' => 'SEC',
            'rideau' => 'RID',
            'volet' => 'VOL',
            'portail' => 'PAU',
            'barriere' => 'BLE',
            'barrière' => 'BLE',
            'niveleur' => 'NIV',
            'plaque' => 'PLQ',
            'sas' => 'SAS',
            'tourniquet' => 'TOU',
            'porte coupe feu' => 'CFE',
            'porte pieton' => 'PPV',
        ];
        
        $libelleNormalized = strtolower(trim($libelle));
        $prefix = 'EQP'; // Défaut
        
        foreach ($prefixMap as $keyword => $pfx) {
            if (str_contains($libelleNormalized, $keyword)) {
                $prefix = $pfx;
                break;
            }
        }
        
        return sprintf('%s%02d', $prefix, $index + 1);
    }
}
