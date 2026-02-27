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
 * 
 * CORRECTIONS 06/02/2026:
 * - Fix extraction anomalies (accès direct valuesAsArray, bypass getEquipDataValue)
 * - Fix HC: utilise nature.value pour déduire le trigramme au lieu de reference7
 * - Fix HC: mapping champs spécifiques (etat1, localisation_site_client1, etc.)
 * - Fix HC: calculateStatusFromFields avec noms de champs HC (_equi_sup)
 * - Mise à jour mapping trigrammes (PAU, PMO, PMA, BLE, PPV, TOU, BRO, BUT, TEL)
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

    // =========================================================================
    // HELPERS D'EXTRACTION DE VALEURS KIZEO
    // =========================================================================

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
                $filtered = array_filter($field['valuesAsArray'], fn($v) => is_string($v) && trim($v) !== '');
                if (!empty($filtered)) {
                    return array_values($filtered); // Reset des clés pour éviter les soucis
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

    // =========================================================================
    // EXTRACTION DES ANOMALIES (FIX 06/02/2026)
    // =========================================================================

    /**
     * Liste de tous les champs d'anomalies possibles dans le subform contrat_de_maintenance
     * 
     * Chaque équipement au contrat contient TOUS ces champs, mais seuls ceux
     * correspondant au type d'équipement sont visibles (hidden=false).
     * Les autres sont masqués (hidden=true) avec valuesAsArray=[""].
     * 
     * On scanne TOUS les champs et on collecte les valeurs non vides.
     */
    private const ANOMALIE_FIELDS_CONTRAT = [
        'anomalies_sec_',                   // Portes sectionnelles
        'anomalie_rapide',                  // Portes rapides  
        'anomalie_rid_vor',                 // Rideaux/Volets roulants
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
    ];

    /**
     * Extrait toutes les anomalies d'un équipement AU CONTRAT
     * 
     * FIX 06/02/2026: Accès DIRECT aux données brutes du champ au lieu de passer
     * par getEquipDataValue(), pour éviter les edge cases de filtrage sur les
     * champs select multiples Kizeo (valuesAsArray avec strings vides).
     * 
     * @param array<string, mixed> $equipData Données brutes de l'équipement
     * @return string|null Anomalies concaténées par " | ", ou null si aucune
     */
    private function extractAnomalies(array $equipData): ?string
    {
        $anomalies = [];
        
        // -----------------------------------------------------------------
        // Parcourir les champs d'anomalies select/multi-select
        // -----------------------------------------------------------------
        foreach (self::ANOMALIE_FIELDS_CONTRAT as $fieldName) {
            if (!isset($equipData[$fieldName]) || !is_array($equipData[$fieldName])) {
                continue;
            }
            
            $fieldData = $equipData[$fieldName];
            
            // Priorité 1 : valuesAsArray (champs select multiples)
            // Contient un tableau des valeurs sélectionnées par le technicien
            if (isset($fieldData['valuesAsArray']) && is_array($fieldData['valuesAsArray'])) {
                $values = array_filter(
                    $fieldData['valuesAsArray'],
                    fn($v) => is_string($v) && trim($v) !== ''
                );
                if (!empty($values)) {
                    $anomalies[] = implode(', ', $values);
                    continue;
                }
            }
            
            // Priorité 2 : value directe (fallback)
            if (isset($fieldData['value']) && is_string($fieldData['value']) && trim($fieldData['value']) !== '') {
                $anomalies[] = trim($fieldData['value']);
            }
        }
        
        // -----------------------------------------------------------------
        // Champs texte libre (autres composants)
        // -----------------------------------------------------------------
        foreach (['autres_composants', 'information_autre_composant'] as $fieldName) {
            $value = $this->getEquipDataValue($equipData, $fieldName);
            if (is_string($value) && trim($value) !== '') {
                $anomalies[] = trim($value);
            }
        }
        
        if (empty($anomalies)) {
            return null;
        }
        
        $result = implode(' | ', $anomalies);
        
        $this->kizeoLogger->debug('Anomalies extraites', [
            'nb_champs' => count($anomalies),
            'anomalies' => $result,
        ]);
        
        return $result;
    }

    /**
     * Extrait les anomalies d'un équipement HORS CONTRAT
     * 
     * Les équipements HC n'ont PAS les mêmes champs anomalies que les équipements
     * au contrat. Ils ont uniquement photo_anomalie (photo, pas de texte).
     * On scanne quand même les champs texte au cas où certains formulaires
     * les incluent, et on ajoute les travaux/pièces remplacées comme info.
     * 
     * @param array<string, mixed> $equipData Données brutes de l'équipement HC
     * @return string|null Anomalies ou null
     */
    private function extractAnomaliesHC(array $equipData): ?string
    {
        $anomalies = [];
        
        // Les HC peuvent avoir des champs anomalies dans certains formulaires
        // On scanne les mêmes champs que le contrat au cas où
        foreach (self::ANOMALIE_FIELDS_CONTRAT as $fieldName) {
            if (!isset($equipData[$fieldName]) || !is_array($equipData[$fieldName])) {
                continue;
            }
            
            $fieldData = $equipData[$fieldName];
            
            if (isset($fieldData['valuesAsArray']) && is_array($fieldData['valuesAsArray'])) {
                $values = array_filter(
                    $fieldData['valuesAsArray'],
                    fn($v) => is_string($v) && trim($v) !== ''
                );
                if (!empty($values)) {
                    $anomalies[] = implode(', ', $values);
                    continue;
                }
            }
            
            if (isset($fieldData['value']) && is_string($fieldData['value']) && trim($fieldData['value']) !== '') {
                $anomalies[] = trim($fieldData['value']);
            }
        }

        $zoneTexte = $this->getEquipDataValue($equipData, 'zone_de_texte2');
        if (is_string($zoneTexte) && trim($zoneTexte) !== '') {
            $anomalies[] = trim($zoneTexte);
        }
        
        return !empty($anomalies) ? implode(' | ', $anomalies) : null;
    }

    // =========================================================================
    // STATUTS ET DESCRIPTIONS
    // =========================================================================

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
     * Calcule le code statut pour un équipement AU CONTRAT
     * basé sur les champs de calcul Kizeo (calcul_*, travaux_obligatoire, etc.)
     * 
     * Utilisé en fallback si le champ 'etat' n'est pas rempli
     * 
     * @param array<string, mixed> $equipData Données de l'équipement
     * @return string|null Code statut (A, B, C, D, E, F, G)
     */
    private function calculateStatusFromFieldsContrat(array $equipData): ?string
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
        
        // États particuliers (NOIR) - spécifiques au contrat
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
        
        // Fallback: utiliser le champ etat directement
        return $this->getEquipDataValue($equipData, 'etat');
    }

    /**
     * Calcule le code statut pour un équipement HORS CONTRAT
     * 
     * FIX 06/02/2026: Les champs HC ont des noms DIFFÉRENTS du contrat :
     * - rien_a_signaler_equipement_su  (au lieu de calcul_rien_a_signaler)
     * - travaux_a_prevoir_equi_sup     (au lieu de calcul_travaux_a_prevoir)
     * - travaux_obligatoire_equi_sup   (au lieu de travaux_obligatoire)
     * - condamne_equi_sup              (n'existe pas en contrat)
     * - equipement_mis_a_l_arret_eq    (au lieu de equipement_mis_a_l_arret_le_j)
     * 
     * @param array<string, mixed> $equipData Données de l'équipement HC
     * @return string|null Code statut (A, B, C, D, E)
     */
    private function calculateStatusFromFieldsHC(array $equipData): ?string
    {
        // Travaux curatifs (ROUGE) = C
        if ($this->getEquipDataValue($equipData, 'travaux_obligatoire_equi_sup') === '1') {
            return 'C';
        }
        
        // Travaux préventifs (ORANGE) = B
        if ($this->getEquipDataValue($equipData, 'travaux_a_prevoir_equi_sup') === '1') {
            return 'B';
        }
        
        // Bon état (VERT) = A
        if ($this->getEquipDataValue($equipData, 'rien_a_signaler_equipement_su') === '1') {
            return 'A';
        }
        
        // Condamné (NOIR) = D
        if ($this->getEquipDataValue($equipData, 'condamne_equi_sup') === '1') {
            return 'D';
        }
        
        // Mis à l'arrêt (NOIR) = E
        if ($this->getEquipDataValue($equipData, 'equipement_mis_a_l_arret_eq') === '1') {
            return 'E';
        }
        
        // Fallback: utiliser le champ etat1 (pas etat) pour les HC
        return $this->getEquipDataValue($equipData, 'etat1');
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
     * Construit les observations pour un équipement HC
     * Inclut les travaux/pièces remplacées
     * 
     * @param array<string, mixed> $equipData Données de l'équipement HC
     * @return string|null Observations formatées ou null si vide
     */
    private function buildObservationsHC(array $equipData): ?string
    {
        $observations = [];
        
        // Travaux/pièces remplacées lors de la visite (champ HC = travaux_pieces_remplaces_lor)
        $travauxPieces = $this->getEquipDataValue($equipData, 'travaux_pieces_remplaces_lor');
        if (!empty($travauxPieces) && is_string($travauxPieces)) {
            $observations[] = $travauxPieces;
        }
        
        return !empty($observations) ? implode(' - ', $observations) : null;
    }

    // =========================================================================
    // DÉDUCTION DU TRIGRAMME DEPUIS nature.value (HC)
    // =========================================================================

    /**
     * Déduit le préfixe type (trigramme) depuis le champ nature.value des HC
     * 
     * FIX 06/02/2026: Les équipements HC n'ont pas de numéro prédéfini.
     * Le type est dans nature.value (ex: "Rideau métallique") et non dans
     * reference7 (qui n'existe pas en HC).
     * 
     * Le mapping est ordonné du plus spécifique au plus générique pour
     * éviter les faux positifs (ex: "porte rapide" avant "rapide").
     * 
     * @param string|null $nature Valeur du champ nature.value
     * @return string Trigramme (SEC, RAP, RID, PAU, etc.) ou 'EQU' par défaut
     */
    private function deduceTypePrefixFromNature(?string $nature): string
    {
        if ($nature === null || trim($nature) === '') {
            return 'EQU';
        }

        $normalized = mb_strtolower(trim($nature));
        
        // Mapping nature → trigramme
        // IMPORTANT: ordonné du plus spécifique au plus générique
        $mapping = [
            'porte sectionnelle'    => 'SEC',
            'sectionnelle'          => 'SEC',
            'porte rapide'          => 'RAP',
            'porte automatique'     => 'RAP',
            'rapide'                => 'RAP',
            'automatique'           => 'RAP',
            'rideau métallique'     => 'RID',
            'rideau metallique'     => 'RID',
            'rideau'                => 'RID',
            'portail coulissant'    => 'PAU',
            'portail battant'       => 'PMO',
            'portail manuel'        => 'PMA',
            'portail'               => 'PAU',
            'barrière levante'      => 'BLE',
            'barriere levante'      => 'BLE',
            'barrière'              => 'BLE',
            'barriere'              => 'BLE',
            'niveleur de quai'      => 'NIV',
            'niveleur'              => 'NIV',
            'porte coupe-feu'       => 'CFE',
            'porte coupe feu'       => 'CFE',
            'coupe-feu'             => 'CFE',
            'coupe feu'             => 'CFE',
            'porte piétonne'        => 'PPV',
            'porte pieton'          => 'PPV',
            'porte piéton'          => 'PPV',
            'tourniquet'            => 'TOU',
            'sas'                   => 'SAS',
            'bloc-roue'             => 'BRO',
            'bloc roue'             => 'BRO',
            'table elevatrice'      => 'TEL',
            'butoir'                => 'BUT',
            'buttoir'               => 'BUT',
        ];

        foreach ($mapping as $keyword => $prefix) {
            if (str_contains($normalized, $keyword)) {
                return $prefix;
            }
        }

        $this->kizeoLogger->warning('Type HC non reconnu, trigramme EQU par défaut', [
            'nature' => $nature,
        ]);

        return 'EQU';
    }

    // =========================================================================
    // TRAITEMENT ÉQUIPEMENT AU CONTRAT
    // =========================================================================

    /**
     * Traite un équipement AU CONTRAT avec mapping complet des champs
     * 
     * Champs Kizeo contrat:
     * - equipement.value → numéro (ex: RID16)
     * - equipement.path  → visite (ex: "MAIRIE DE MOIRANS\CE1" → CE1)
     * - reference7.value → libellé (ex: "Rideau metallique")
     * - reference5.value → marque
     * - reference2.value → mise en service
     * - reference6.value → n° de série
     * - reference3.value → hauteur
     * - reference1.value → largeur
     * - mode_fonctionnement_2.value → mode fonctionnement
     * - localisation_site_client.value → repère site client
     * - etat.value → code lettre (A, B, C...)
     * - anomalie_*.valuesAsArray → anomalies
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
        // 5. CHAMPS ENRICHIS (MAPPING CONTRAT)
        // ==========================================================================
        
        // Libellé équipement (type: Porte Rapide, Rideau metallique, Niveleur, etc.)
        $entity->setLibelleEquipement(
            $this->getEquipDataValue($equipData, 'reference7') ?? ''
        );
        
        // Repère site client (localisation sur le site)
        $entity->setRepereSiteClient(
            $this->getEquipDataValue($equipData, 'localisation_site_client')
        );
        
        // Année de mise en service
        $entity->setMiseEnService(
            $this->getEquipDataValue($equipData, 'reference2')
        );
        
        // Numéro de série
        $entity->setNumeroSerie(
            $this->getEquipDataValue($equipData, 'reference6')
        );
        
        // Marque du fabricant
        $entity->setMarque(
            $this->getEquipDataValue($equipData, 'reference5')
        );
        
        // Mode de fonctionnement (automatique, manuel, motorisé)
        $entity->setModeFonctionnement(
            $this->getEquipDataValue($equipData, 'mode_fonctionnement_2')
        );
        
        // Dimensions
        $entity->setHauteur(
            $this->getEquipDataValue($equipData, 'reference3')
        );
        $entity->setLargeur(
            $this->getEquipDataValue($equipData, 'reference1')
        );
        
        // ==========================================================================
        // STATUT ÉQUIPEMENT AU CONTRAT (Codes officiels SOMAFI)
        // ==========================================================================
        
        // Récupérer le code lettre depuis Kizeo ou le calculer depuis les champs
        $statusCode = $this->getEquipDataValue($equipData, 'etat');
        if (empty($statusCode)) {
            $statusCode = $this->calculateStatusFromFieldsContrat($equipData);
        }
        
        // statut_equipement = Code lettre (A, B, C, D, E, F, G)
        $entity->setStatutEquipement($statusCode);
        
        // etat_equipement = Description textuelle complète
        $entity->setEtatEquipement(
            $this->getStatusDescription($statusCode, false)
        );
        
        // ==========================================================================
        // ANOMALIES (FIX 06/02/2026 - extraction directe des valuesAsArray)
        // ==========================================================================
        $anomalies = $this->extractAnomalies($equipData);
        $entity->setAnomalies($anomalies);
        
        // Log si statut C/B mais aucune anomalie trouvée (aide au debug)
        if (in_array($statusCode, ['B', 'C'], true) && $anomalies === null) {
            $this->kizeoLogger->warning('Équipement statut B/C sans anomalie détectée', [
                'agency' => $agencyCode,
                'numero' => $numero,
                'statut' => $statusCode,
                'data_id' => $dataId,
            ]);
        }
        
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
                'anomalies' => $anomalies,
            ]);
        }

        $result['created'] = 1;
        return $result;
    }

    // =========================================================================
    // TRAITEMENT ÉQUIPEMENT HORS CONTRAT (FIX COMPLET 06/02/2026)
    // =========================================================================

    /**
     * Traite un équipement HORS CONTRAT avec mapping SPÉCIFIQUE HC
     * 
     * FIX 06/02/2026: Les champs HC sont DIFFÉRENTS du contrat :
     * - nature.value         → type/libellé (au lieu de reference7)
     * - etat1.value          → code lettre (au lieu de etat)
     * - localisation_site_client1 → repère (au lieu de localisation_site_client)
     * - mode_fonctionnement_ → mode (au lieu de mode_fonctionnement_2)
     * - marque.value         → marque (au lieu de reference5)
     * - hauteur.value        → hauteur (au lieu de reference3)
     * - largeur.value        → largeur (au lieu de reference1)
     * - annee.value          → mise en service (au lieu de reference2)
     * - n_de_serie.value     → n° série (au lieu de reference6)
     * - rien_a_signaler_equipement_su, travaux_a_prevoir_equi_sup, etc.
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
        // 2. EXTRACTION DU TYPE DEPUIS nature.value (FIX 06/02/2026)
        // ==========================================================================
        
        // Le type/libellé HC vient de nature.value (ex: "Rideau métallique")
        // et NON de reference7 (qui n'existe pas en HC)
        $natureValue = $this->getEquipDataValue($equipData, 'nature');
        $libelle = $natureValue ?? 'Équipement HC';
        
        // Déduire le trigramme depuis la nature pour la génération du numéro
        $typePrefix = $this->deduceTypePrefixFromNature($natureValue);
            
        $annee = $dateVisite ? (new \DateTime($dateVisite))->format('Y') : date('Y');
        
        // Générer le numéro d'équipement basé sur le type déduit
        // Utilise le OffContractNumberGenerator pour obtenir le prochain numéro
        // disponible pour ce type chez ce client (ex: RID28 si RID27 existe déjà)
        $numero = $this->numberGenerator->generate(
            $agencyCode,
            $idContact,
            $typePrefix
        );

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
        
        // ==========================================================================
        // CHAMPS ENRICHIS - MAPPING SPÉCIFIQUE HC (FIX 06/02/2026)
        // Les noms de champs HC sont DIFFÉRENTS de ceux du contrat !
        // ==========================================================================
        
        // Repère site client (champ HC = localisation_site_client1, PAS localisation_site_client)
        $entity->setRepereSiteClient(
            $this->getEquipDataValue($equipData, 'localisation_site_client1')
        );
        
        // Mise en service (champ HC = annee, PAS reference2)
        $entity->setMiseEnService(
            $this->getEquipDataValue($equipData, 'annee')
        );
        
        // Numéro de série (champ HC = n_de_serie, PAS reference6)
        $entity->setNumeroSerie(
            $this->getEquipDataValue($equipData, 'n_de_serie')
        );
        
        // Marque (champ HC = marque, PAS reference5)
        $entity->setMarque(
            $this->getEquipDataValue($equipData, 'marque')
        );
        
        // Mode de fonctionnement (champ HC = mode_fonctionnement_, PAS mode_fonctionnement_2)
        $entity->setModeFonctionnement(
            $this->getEquipDataValue($equipData, 'mode_fonctionnement_')
        );
        
        // Hauteur (champ HC = hauteur, PAS reference3)
        $entity->setHauteur(
            $this->getEquipDataValue($equipData, 'hauteur')
        );
        
        // Largeur (champ HC = largeur, PAS reference1)
        $entity->setLargeur(
            $this->getEquipDataValue($equipData, 'largeur')
        );
        
        // ==========================================================================
        // STATUT ÉQUIPEMENT HC (FIX 06/02/2026 - champs spécifiques HC)
        // ==========================================================================
        
        // Le code lettre HC vient de etat1 (PAS etat)
        $statusCode = $this->getEquipDataValue($equipData, 'etat1');
        if (empty($statusCode)) {
            // Fallback: calculer depuis les champs calcul HC (noms différents du contrat)
            $statusCode = $this->calculateStatusFromFieldsHC($equipData);
        }
        
        $entity->setStatutEquipement($statusCode);
        
        $entity->setEtatEquipement(
            $this->getStatusDescription($statusCode, true)
        );
        
        // Anomalies HC (scan des champs au cas où + spécifiques HC)
        $entity->setAnomalies(
            $this->extractAnomaliesHC($equipData)
        );
        
        // Observations HC (travaux/pièces remplacées)
        $entity->setObservations(
            $this->buildObservationsHC($equipData)
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
                'nature' => $natureValue,
                'type_prefix' => $typePrefix,
                'statut' => $statusCode,
                'index' => $index,
            ]);
        }

        $result['created'] = 1;
        return $result;
    }

    // =========================================================================
    // MÉTHODES UTILITAIRES
    // =========================================================================

    /**
     * Extrait une valeur d'un champ Kizeo de premier niveau (fields)
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
     *     "MAIRIE DE MOIRANS\\CE1" -> "CE1"
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
}