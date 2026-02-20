<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use App\DTO\Kizeo\ExtractedEquipment;
use App\DTO\Kizeo\ExtractedFormData;
use App\DTO\Kizeo\ExtractedMedia;
use Psr\Log\LoggerInterface;

/**
 * Service d'extraction des données depuis le JSON Kizeo
 * 
 * Parse un formulaire CR Technicien et retourne un DTO structuré
 * contenant les informations du client, les équipements et les médias.
 * 
 * CORRECTIONS 06/02/2026:
 * - FIX #1 CRITIQUE: Scan dynamique de TOUS les champs type=photo au lieu
 *   d'une liste hardcodée incomplète (aucune photo n'était extraite)
 * - FIX #2: HC medias utilisent equipmentIndex au lieu d'un placeholder "HC_x"
 *   (permettant la résolution via generatedNumbers dans PhotoPersister)
 * - FIX #3: HC type équipement extrait depuis "nature" (pas "type_equipement")
 * 
 * CORRECTIONS 06/02/2026 v2 — ALIGNEMENT HC:
 * - FIX #4: Anomalies contrat — scan des 15 vrais champs Kizeo avec valuesAsArray
 * - FIX #5: HC — extraction de TOUS les champs (repère, dimensions, mode fonctionnement,
 *   mise en service, n° série) avec les noms de champs HC spécifiques
 * - FIX #6: HC — calcul du statut depuis les champs HC (_equi_sup) 
 * - FIX #7: HC — observations (travaux/pièces remplacées)
 * - FIX #8: Contrat — calcul du statut en fallback si etat.value est vide
 * - FIX #9: Contrat — observations (temps estimé, nacelle)
 */
class FormDataExtractor
{
    // =========================================================================
    // CONSTANTES — CHAMPS ANOMALIES (identiques à KizeoFormProcessor)
    // =========================================================================

    /**
     * Liste de tous les champs d'anomalies possibles dans contrat_de_maintenance
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

    // =========================================================================
    // CONSTANTES — DESCRIPTIONS STATUTS
    // =========================================================================

    private const STATUS_DESCRIPTIONS_CONTRAT = [
        'A' => 'Bon état de fonctionnement le jour de la visite',
        'B' => 'Travaux préventifs',
        'C' => 'Travaux curatifs',
        'D' => 'Équipement inaccessible le jour de la visite',
        'E' => 'Équipement à l\'arrêt le jour de la visite',
        'F' => 'Équipement mis à l\'arrêt lors de l\'intervention',
        'G' => 'Équipement non présent sur site',
    ];

    private const STATUS_DESCRIPTIONS_HC = [
        'A' => 'Bon état de fonctionnement le jour de la visite',
        'B' => 'Travaux préventifs',
        'C' => 'Travaux curatifs',
        'D' => 'Équipement à l\'arrêt le jour de la visite',
        'E' => 'Équipement mis à l\'arrêt lors de l\'intervention',
    ];

    public function __construct(
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    /**
     * Extrait les données d'un formulaire Kizeo
     * 
     * @param array $formData Données JSON d'un formulaire (un élément de la réponse /data/unread)
     * @param int $formId ID du formulaire Kizeo
     * @return ExtractedFormData DTO avec toutes les données extraites
     */
    public function extract(array $formData, int $formId): ExtractedFormData
    {
        $dataId = (int) ($formData['id'] ?? 0);
        $fields = $formData['fields'] ?? [];

        $this->kizeoLogger->debug('Extraction données CR', [
            'form_id' => $formId,
            'data_id' => $dataId,
            'nb_fields' => count($fields),
        ]);

        // 1. Extraire les données globales du CR
        $idContact = $this->extractIdContact($fields);
        $idSociete = $this->extractStringValue($fields, 'id_societe');
        $raisonSociale = $this->extractStringValue($fields, 'raison_sociale')
            ?? $this->extractStringValue($fields, 'nom_client')
            ?? $this->extractStringValue($fields, 'client')
            ?? $this->extractStringValue($fields, 'societe');
        $dateVisite = $this->extractDateVisite($fields);
        $annee = $dateVisite?->format('Y');
        $trigramme = $this->extractStringValue($fields, 'trigramme');

        // 2. Extraire les équipements au contrat
        $contractEquipments = $this->extractContractEquipments($fields);

        // 3. Extraire les équipements hors contrat
        $offContractEquipments = $this->extractOffContractEquipments($fields);

        // 4. Extraire les médias (photos) — scan dynamique de tous les champs type=photo
        $medias = $this->extractMedias($fields, $contractEquipments, $offContractEquipments);

        $visite = $this->determineVisite($contractEquipments);

        $extracted = new ExtractedFormData(
            formId: $formId,
            dataId: $dataId,
            idContact: $idContact,
            idSociete: $idSociete,
            raisonSociale: $raisonSociale,
            dateVisite: $dateVisite,
            annee: $annee,
            visite: $visite,
            trigramme: $trigramme,
            contractEquipments: $contractEquipments,
            offContractEquipments: $offContractEquipments,
            medias: $medias,
        );

        $this->kizeoLogger->info('Données CR extraites', $extracted->toLogContext());

        return $extracted;
    }

    /**
     * Détermine la visite principale depuis les équipements au contrat
     */
    private function determineVisite(array $contractEquipments): string
    {
        foreach ($contractEquipments as $equipment) {
            if ($equipment->hasValidVisite()) {
                return $equipment->getNormalizedVisite();
            }
        }
        return 'CEA'; // Fallback CEA (visite unique annuelle)
    }

    /**
     * Extrait l'ID contact depuis le champ id_client_
     */
    private function extractIdContact(array $fields): ?int
    {
        // Le champ peut être "id_client_" ou "id_client"
        $value = $this->extractStringValue($fields, 'id_client_')
            ?? $this->extractStringValue($fields, 'id_client');

        if ($value === null || $value === '') {
            $this->kizeoLogger->warning('ID Contact manquant dans le CR');
            return null;
        }

        // S'assurer que c'est un entier valide
        if (!is_numeric($value)) {
            $this->kizeoLogger->warning('ID Contact non numérique', ['value' => $value]);
            return null;
        }

        return (int) $value;
    }

    /**
     * Extrait la date de visite depuis date_et_heure1
     */
    private function extractDateVisite(array $fields): ?\DateTimeInterface
    {
        $value = $this->extractStringValue($fields, 'date_et_heure1');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Format Kizeo : "2025-01-08 14:30:00" ou "2025-01-08"
            return new \DateTime($value);
        } catch (\Exception $e) {
            $this->kizeoLogger->warning('Date de visite invalide', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // ÉQUIPEMENTS AU CONTRAT
    // =========================================================================

    /**
     * Extrait les équipements au contrat depuis contrat_de_maintenance
     * 
     * @return ExtractedEquipment[]
     */
    private function extractContractEquipments(array $fields): array
    {
        $equipments = [];
        $contractData = $fields['contrat_de_maintenance']['value'] ?? [];

        if (!is_array($contractData)) {
            return [];
        }

        foreach ($contractData as $index => $item) {
            $equipment = $this->parseContractEquipment($item, $index);
            
            if ($equipment !== null) {
                $equipments[] = $equipment;
            }
        }

        $this->kizeoLogger->debug('Équipements contrat extraits', [
            'count' => count($equipments),
        ]);

        return $equipments;
    }

    /**
     * Parse un équipement au contrat depuis le JSON
     * 
     * MAPPING KIZEO CONTRAT (corrigé 30/01/2026 + 06/02/2026):
     * - equipement.value  → numéro (ex: RID16)
     * - equipement.path   → visite (ex: "MAIRIE DE MOIRANS\CE1" → CE1)
     * - reference7.value  → libellé (ex: "Rideau metallique")
     * - reference5.value  → marque
     * - reference2.value  → mise en service
     * - reference6.value  → n° de série
     * - reference3.value  → hauteur
     * - reference1.value  → largeur
     * - mode_fonctionnement_2.value → mode fonctionnement
     * - localisation_site_client.value → repère site client
     * - etat.value        → code lettre (A, B, C...) avec fallback calcul
     * - anomalie_*.valuesAsArray → anomalies (15 champs)
     */
    private function parseContractEquipment(array $item, int $index): ?ExtractedEquipment
    {
        // Le numéro d'équipement est dans equipement.value
        $numero = $this->getNestedValue($item, 'equipement', 'value');
        
        if ($numero === null || trim($numero) === '') {
            $this->kizeoLogger->debug('Équipement contrat sans numéro', ['index' => $index]);
            return null;
        }

        // La visite est extraite du path (ex: "CLIENT\CE1" -> "CE1")
        $path = $this->getNestedValue($item, 'equipement', 'path');
        $visite = $this->extractVisiteFromPath($path);

        // Code statut (lettre A-G)
        $statutCode = $this->getNestedValue($item, 'etat', 'value');
        if (empty($statutCode)) {
            // Fallback: calculer depuis les champs de calcul
            $statutCode = $this->calculateStatusFromFieldsContrat($item);
        }

        return ExtractedEquipment::createContract(
            numeroEquipement: trim($numero),
            visite: $visite ?? 'CE1',
            // reference7 = Libellé équipement (Rideau métallique, Porte Rapide, etc.)
            libelleEquipement: $this->getNestedValue($item, 'reference7', 'value'),
            // Type équipement (pas de champ direct, utiliser reference7)
            typeEquipement: $this->getNestedValue($item, 'reference7', 'value'),
            // reference5 = Marque (JAVEY, DITEC, La Toulousaine, etc.)
            marque: $this->getNestedValue($item, 'reference5', 'value'),
            // mode_fonctionnement_2 = Mode fonctionnement (motorisé, manuel)
            modeFonctionnement: $this->getNestedValue($item, 'mode_fonctionnement_2', 'value'),
            // localisation_site_client = Repère site client (Charcuterie, QUAI, etc.)
            repereSiteClient: $this->getNestedValue($item, 'localisation_site_client', 'value'),
            // reference2 = Année mise en service (2003, 2014, etc.)
            miseEnService: $this->getNestedValue($item, 'reference2', 'value'),
            // reference6 = Numéro de série
            numeroSerie: $this->getNestedValue($item, 'reference6', 'value'),
            // reference3 = Hauteur en mm
            hauteur: $this->getNestedValue($item, 'reference3', 'value'),
            // reference1 = Largeur en mm
            largeur: $this->getNestedValue($item, 'reference1', 'value'),
            // Pas de champ longueur dans le formulaire Kizeo analysé
            longueur: null,
            // Statut = code lettre (A, B, C, D, E, F, G)
            statutEquipement: $statutCode,
            // État = description textuelle
            etatEquipement: $this->getStatusDescription($statutCode, isHorsContrat: false),
            // Anomalies = scan des 15 champs réels avec valuesAsArray
            anomalies: $this->extractAnomaliesContrat($item),
            // Observations = temps estimé + nacelle
            observations: $this->buildObservationsContrat($item),
            rawData: $item,
        );
    }

    /**
     * Extrait la visite depuis le path Kizeo
     * 
     * Exemples de paths :
     * - "GROUPE MAURIN\CE1" → "CE1"
     * - "CLIENT XYZ\CE2\Sous-niveau" → "CE2"
     */
    private function extractVisiteFromPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Le path utilise \ comme séparateur
        $parts = explode('\\', $path);

        // La visite est généralement en position 1
        if (isset($parts[1])) {
            $visite = strtoupper(trim($parts[1]));

            // Valider le format (CE1, CE2, CE3, CE4, CEA)
            if (preg_match('/^CE[A1-4]$/', $visite)) {
                return $visite;
            }
        }

        return null;
    }

    // =========================================================================
    // ÉQUIPEMENTS HORS CONTRAT (FIX #5, #6, #7)
    // =========================================================================

    /**
     * Extrait les équipements hors contrat depuis tableau2
     * 
     * @return ExtractedEquipment[]
     */
    private function extractOffContractEquipments(array $fields): array
    {
        $equipments = [];
        $offContractData = $fields['tableau2']['value'] ?? [];

        if (!is_array($offContractData)) {
            return [];
        }

        foreach ($offContractData as $index => $item) {
            $equipment = $this->parseOffContractEquipment($item, $index);
            
            if ($equipment !== null) {
                $equipments[] = $equipment;
            }
        }

        $this->kizeoLogger->debug('Équipements hors contrat extraits', [
            'count' => count($equipments),
        ]);

        return $equipments;
    }

    /**
     * Parse un équipement hors contrat depuis le JSON
     * 
     * CORRECTION 06/02/2026 v2 — ALIGNEMENT COMPLET avec KizeoFormProcessor:
     * 
     * MAPPING KIZEO HC (noms de champs DIFFÉRENTS du contrat !) :
     * - nature.value                   → type/libellé (ex: "Rideau métallique")
     * - marque.value                   → marque (pas reference5)
     * - localisation_site_client1.value → repère site client (pas localisation_site_client)
     * - annee.value                    → mise en service (pas reference2)
     * - n_de_serie.value               → n° de série (pas reference6)
     * - mode_fonctionnement_.value     → mode fonctionnement (pas mode_fonctionnement_2)
     * - hauteur.value                  → hauteur (pas reference3)
     * - largeur.value                  → largeur (pas reference1)
     * - etat1.value                    → code lettre statut (pas etat)
     * - travaux_pieces_remplaces_lor   → observations
     * 
     * CHAMPS CALCUL STATUT HC (suffixe _equi_sup) :
     * - rien_a_signaler_equipement_su  → A (pas calcul_rien_a_signaler)
     * - travaux_a_prevoir_equi_sup     → B (pas calcul_travaux_a_prevoir)
     * - travaux_obligatoire_equi_sup   → C (pas travaux_obligatoire)
     * - condamne_equi_sup              → D (n'existe pas en contrat)
     * - equipement_mis_a_l_arret_eq    → E (pas equipement_mis_a_l_arret_le_j)
     */
    private function parseOffContractEquipment(array $item, int $index): ?ExtractedEquipment
    {
        // FIX #3: Pour hors contrat, le type vient de "nature" (ex: "Rideau métallique")
        $typeEquipement = $this->getNestedValue($item, 'nature', 'value')
            ?? $this->getNestedValue($item, 'type_equipement', 'value')
            ?? $this->getNestedValue($item, 'type', 'value');

        // Code statut HC (lettre A-E)
        $statutCode = $this->getNestedValue($item, 'etat1', 'value');
        if (empty($statutCode)) {
            // Fallback: calculer depuis les champs de calcul HC
            $statutCode = $this->calculateStatusFromFieldsHC($item);
        }

        return ExtractedEquipment::createOffContract(
            kizeoIndex: $index,
            typeEquipement: $typeEquipement,
            // Libellé = nature (ex: "Rideau métallique")
            libelleEquipement: $typeEquipement,
            // Marque (champ HC = marque, PAS reference5)
            marque: $this->getNestedValue($item, 'marque', 'value'),
            // Mode fonctionnement (champ HC = mode_fonctionnement_, PAS mode_fonctionnement_2)
            modeFonctionnement: $this->getNestedValue($item, 'mode_fonctionnement_', 'value'),
            // Repère site client (champ HC = localisation_site_client1, PAS localisation_site_client)
            repereSiteClient: $this->getNestedValue($item, 'localisation_site_client1', 'value'),
            // Mise en service (champ HC = annee, PAS reference2)
            miseEnService: $this->getNestedValue($item, 'annee', 'value'),
            // N° de série (champ HC = n_de_serie, PAS reference6)
            numeroSerie: $this->getNestedValue($item, 'n_de_serie', 'value'),
            // Hauteur (champ HC = hauteur, PAS reference3)
            hauteur: $this->getNestedValue($item, 'hauteur', 'value'),
            // Largeur (champ HC = largeur, PAS reference1)
            largeur: $this->getNestedValue($item, 'largeur', 'value'),
            // Statut = code lettre (A, B, C, D, E)
            statutEquipement: $statutCode,
            // État = description textuelle
            etatEquipement: $this->getStatusDescription($statutCode, isHorsContrat: true),
            // Anomalies HC (scan champs contrat + spécifiques HC)
            anomalies: $this->extractAnomaliesHC($item),
            // Observations HC (travaux/pièces remplacées)
            observations: $this->buildObservationsHC($item),
            rawData: $item,
        );
    }

    // =========================================================================
    // EXTRACTION DES ANOMALIES (FIX #4)
    // =========================================================================

    /**
     * Extrait les anomalies d'un équipement AU CONTRAT
     * 
     * FIX #4 (06/02/2026): Scan des 15 vrais champs Kizeo avec parsing
     * de valuesAsArray (champs select multiples) au lieu de la liste
     * générique de 5 champs qui ne correspondait à rien dans le JSON réel.
     * 
     * Chaque équipement contient les 15 champs, mais seuls ceux correspondant
     * au type sont visibles (hidden=false). Les masqués ont valuesAsArray=[""].
     * On collecte TOUTES les valeurs non vides.
     * 
     * @param array $equipData Données brutes de l'équipement
     * @return string|null Anomalies concaténées par " | ", ou null si aucune
     */
    private function extractAnomaliesContrat(array $equipData): ?string
    {
        $anomalies = [];
        
        // -----------------------------------------------------------------
        // Parcourir les 15 champs d'anomalies select/multi-select
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
            $value = $this->getNestedValue($equipData, $fieldName, 'value');
            if ($value !== null && trim($value) !== '') {
                $anomalies[] = trim($value);
            }
        }
        
        if (empty($anomalies)) {
            return null;
        }
        
        $result = implode(' | ', $anomalies);
        
        $this->kizeoLogger->debug('Anomalies contrat extraites', [
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
     * On scanne quand même les champs contrat au cas où certains formulaires
     * les incluent.
     * 
     * @param array $equipData Données brutes de l'équipement HC
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
        
        return !empty($anomalies) ? implode(' | ', $anomalies) : null;
    }

    // =========================================================================
    // CALCUL DES STATUTS (FIX #6, #8)
    // =========================================================================

    /**
     * Calcule le code statut pour un équipement AU CONTRAT
     * basé sur les champs de calcul Kizeo (calcul_*, travaux_obligatoire, etc.)
     * 
     * Utilisé en fallback si le champ 'etat' n'est pas rempli.
     * 
     * @param array $equipData Données de l'équipement
     * @return string|null Code statut (A, B, C, D, E, F, G)
     */
    private function calculateStatusFromFieldsContrat(array $equipData): ?string
    {
        // Travaux curatifs (ROUGE) = C
        if ($this->getNestedValue($equipData, 'travaux_obligatoire', 'value') === '1') {
            return 'C';
        }
        
        // Travaux préventifs (ORANGE) = B
        if ($this->getNestedValue($equipData, 'calcul_travaux_a_prevoir', 'value') === '1') {
            return 'B';
        }
        
        // Bon état (VERT) = A
        if ($this->getNestedValue($equipData, 'calcul_rien_a_signaler', 'value') === '1') {
            return 'A';
        }
        
        // États particuliers (NOIR) - spécifiques au contrat
        if ($this->getNestedValue($equipData, 'equipement_inaccessible_le_jo', 'value') === '1') {
            return 'D'; // Inaccessible
        }
        if ($this->getNestedValue($equipData, 'equipement_a_l_arret_le_jour_', 'value') === '1') {
            return 'E'; // À l'arrêt
        }
        if ($this->getNestedValue($equipData, 'equipement_mis_a_l_arret_le_j', 'value') === '1') {
            return 'F'; // Mis à l'arrêt
        }
        if ($this->getNestedValue($equipData, 'equipement_non_present_sur_si', 'value') === '1') {
            return 'G'; // Non présent
        }
        
        return null;
    }

    /**
     * Calcule le code statut pour un équipement HORS CONTRAT
     * 
     * FIX #6: Les champs HC ont des noms DIFFÉRENTS du contrat :
     * - rien_a_signaler_equipement_su  (au lieu de calcul_rien_a_signaler)
     * - travaux_a_prevoir_equi_sup     (au lieu de calcul_travaux_a_prevoir)
     * - travaux_obligatoire_equi_sup   (au lieu de travaux_obligatoire)
     * - condamne_equi_sup              (n'existe pas en contrat)
     * - equipement_mis_a_l_arret_eq    (au lieu de equipement_mis_a_l_arret_le_j)
     * 
     * @param array $equipData Données de l'équipement HC
     * @return string|null Code statut (A, B, C, D, E)
     */
    private function calculateStatusFromFieldsHC(array $equipData): ?string
    {
        // Travaux curatifs (ROUGE) = C
        if ($this->getNestedValue($equipData, 'travaux_obligatoire_equi_sup', 'value') === '1') {
            return 'C';
        }
        
        // Travaux préventifs (ORANGE) = B
        if ($this->getNestedValue($equipData, 'travaux_a_prevoir_equi_sup', 'value') === '1') {
            return 'B';
        }
        
        // Bon état (VERT) = A
        if ($this->getNestedValue($equipData, 'rien_a_signaler_equipement_su', 'value') === '1') {
            return 'A';
        }
        
        // Condamné (NOIR) = D
        if ($this->getNestedValue($equipData, 'condamne_equi_sup', 'value') === '1') {
            return 'D';
        }
        
        // Mis à l'arrêt (NOIR) = E
        if ($this->getNestedValue($equipData, 'equipement_mis_a_l_arret_eq', 'value') === '1') {
            return 'E';
        }
        
        return null;
    }

    /**
     * Retourne la description textuelle du statut équipement
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

        $descriptions = $isHorsContrat
            ? self::STATUS_DESCRIPTIONS_HC
            : self::STATUS_DESCRIPTIONS_CONTRAT;

        return $descriptions[strtoupper($statusCode)] ?? null;
    }

    // =========================================================================
    // OBSERVATIONS (FIX #7, #9)
    // =========================================================================

    /**
     * Construit les observations pour un équipement AU CONTRAT
     * Inclut le temps de travail estimé et les besoins en nacelle
     * 
     * @param array $equipData Données de l'équipement
     * @return string|null Observations formatées ou null si vide
     */
    private function buildObservationsContrat(array $equipData): ?string
    {
        $observations = [];
        
        // Temps de travail estimé pour les réparations
        $tempsEstime = $this->getNestedValue($equipData, 'temps_de_travail_estime_pour_', 'value');
        if (!empty($tempsEstime)) {
            $observations[] = "Temps estimé: {$tempsEstime}";
        }
        
        // Hauteur de nacelle nécessaire
        $nacelle = $this->getNestedValue($equipData, 'hauteur_de_nacelle_necessaire', 'value');
        if (!empty($nacelle)) {
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
     * @param array $equipData Données de l'équipement HC
     * @return string|null Observations formatées ou null si vide
     */
    private function buildObservationsHC(array $equipData): ?string
    {
        $observations = [];
        
        // Travaux/pièces remplacées lors de la visite (champ HC = travaux_pieces_remplaces_lor)
        $travauxPieces = $this->getNestedValue($equipData, 'travaux_pieces_remplaces_lor', 'value');
        if (!empty($travauxPieces)) {
            $observations[] = $travauxPieces;
        }
        
        return !empty($observations) ? implode(' - ', $observations) : null;
    }

    // =========================================================================
    // EXTRACTION DES MÉDIAS (PHOTOS)
    // =========================================================================

    /**
     * Extrait les médias (photos) depuis les sous-formulaires
     * 
     * CORRECTION 06/02/2026:
     * Scan DYNAMIQUE de tous les champs avec type=photo et valeur non vide,
     * au lieu d'une liste hardcodée qui ne correspondait pas aux vrais noms
     * de champs Kizeo (photo_etiquette_somafi, photo2, photo3, etc.)
     * 
     * @param array $fields Champs du formulaire
     * @param ExtractedEquipment[] $contractEquipments Équipements au contrat
     * @param ExtractedEquipment[] $offContractEquipments Équipements hors contrat
     * @return ExtractedMedia[]
     */
    private function extractMedias(
        array $fields,
        array $contractEquipments,
        array $offContractEquipments
    ): array {
        $medias = [];

        // =====================================================================
        // 1. Photos des équipements AU CONTRAT (contrat_de_maintenance)
        // Scan dynamique : on parcourt TOUS les champs de chaque équipement
        // et on garde ceux qui ont type=photo + value non vide
        // =====================================================================
        $contractData = $fields['contrat_de_maintenance']['value'] ?? [];
        if (is_array($contractData)) {
            foreach ($contractData as $index => $item) {
                $equipmentNumero = $this->getNestedValue($item, 'equipement', 'value');
                if ($equipmentNumero === null || trim($equipmentNumero) === '') {
                    continue;
                }

                $this->extractAllPhotoFields($item, trim($equipmentNumero), true, null, $medias);
            }
        }

        // =====================================================================
        // 2. Photos des équipements HORS CONTRAT (tableau2)
        // FIX #2: On utilise equipmentIndex (pas un placeholder "HC_x")
        // Le numéro réel sera résolu par generatedNumbers dans PhotoPersister
        // =====================================================================
        $offContractData = $fields['tableau2']['value'] ?? [];
        if (is_array($offContractData)) {
            foreach ($offContractData as $index => $item) {
                // equipmentNumero = null, sera résolu via equipmentIndex + generatedNumbers
                $this->extractAllPhotoFields($item, null, false, $index, $medias);
            }
        }

        $this->kizeoLogger->debug('Médias extraits', [
            'total' => count($medias),
            'contract' => count(array_filter($medias, fn(ExtractedMedia $m) => $m->isContract)),
            'off_contract' => count(array_filter($medias, fn(ExtractedMedia $m) => !$m->isContract)),
        ]);

        return $medias;
    }

    /**
     * Scanne DYNAMIQUEMENT tous les champs d'un équipement pour trouver les photos.
     * 
     * Au lieu d'une liste hardcodée de noms de champs, on inspecte chaque champ :
     * - Si type === "photo" ET value non vide → on crée un ExtractedMedia
     * - Le fieldName (clé du champ) est conservé tel quel pour le mapping vers
     *   les colonnes de la table photos (avec résolution d'alias dans PhotoPersister)
     * 
     * Exemples de champs trouvés dynamiquement :
     *   AU CONTRAT : photo_etiquette_somafi, photo2, photo_fixation_coulisse, 
     *                photo_complementaire_equipeme, photo_coffret_de_commande...
     *   HORS CONTRAT : photo_etiquette_somafi1, photo3, photo_plaque_signaletique...
     * 
     * @param array $equipmentData Données brutes de l'équipement (sous-formulaire)
     * @param string|null $equipmentNumero Numéro d'équipement (null pour HC → résolu via index)
     * @param bool $isContract Équipement au contrat ou hors contrat
     * @param int|null $equipmentIndex Index dans tableau2 (HC uniquement)
     * @param ExtractedMedia[] &$medias Collection de médias (par référence)
     */
    private function extractAllPhotoFields(
        array $equipmentData,
        ?string $equipmentNumero,
        bool $isContract,
        ?int $equipmentIndex,
        array &$medias,
    ): void {
        foreach ($equipmentData as $fieldName => $fieldData) {
            // Vérifier que c'est bien un champ structuré avec un type
            if (!is_array($fieldData)) {
                continue;
            }

            $fieldType = $fieldData['type'] ?? null;
            $fieldValue = $fieldData['value'] ?? null;

            // On ne garde que les champs de type "photo" avec une valeur non vide
            if ($fieldType !== 'photo' || $fieldValue === null || $fieldValue === '') {
                continue;
            }

            // La valeur peut être un string ou un tableau de strings
            $mediaNames = is_array($fieldValue)
                ? array_filter($fieldValue, fn($v) => is_string($v) && $v !== '')
                : (is_string($fieldValue) ? [$fieldValue] : []);

            foreach ($mediaNames as $photoIndex => $mediaName) {
                $photoType = ExtractedMedia::normalizePhotoType($fieldName);

                if ($isContract) {
                    $medias[] = ExtractedMedia::createForContract(
                        mediaName: $mediaName,
                        equipmentNumero: $equipmentNumero,
                        photoType: $photoType,
                        photoIndex: count($mediaNames) > 1 ? $photoIndex + 1 : null,
                        fieldName: $fieldName,
                    );
                } else {
                    // FIX #2: Passer equipmentIndex pour que PhotoPersister puisse
                    // résoudre le numéro via generatedNumbers[$equipmentIndex]
                    $medias[] = ExtractedMedia::createForOffContract(
                        mediaName: $mediaName,
                        equipmentNumero: $equipmentNumero ?? sprintf('HC_%d', $equipmentIndex),
                        fieldName: $fieldName,
                        equipmentIndex: $equipmentIndex,
                    );
                }
            }
        }
    }

    // =========================================================================
    // HELPERS D'EXTRACTION
    // =========================================================================

    /**
     * Extrait une valeur string depuis un champ
     */
    private function extractStringValue(array $fields, string $fieldName): ?string
    {
        $field = $fields[$fieldName] ?? null;

        if ($field === null) {
            return null;
        }

        // Le champ peut être directement une string ou un objet avec 'value'
        if (is_string($field)) {
            return $field;
        }

        if (is_array($field) && isset($field['value'])) {
            $value = $field['value'];
            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * Récupère une valeur imbriquée dans un tableau
     */
    private function getNestedValue(array $data, string $key1, string $key2): ?string
    {
        $value = $data[$key1][$key2] ?? null;

        if ($value === null) {
            return null;
        }

        // Gérer le cas où la valeur est un tableau (prendre le premier élément)
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }
}