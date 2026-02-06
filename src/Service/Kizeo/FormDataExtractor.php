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
 */
class FormDataExtractor
{
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

        $extracted = new ExtractedFormData(
            formId: $formId,
            dataId: $dataId,
            idContact: $idContact,
            idSociete: $idSociete,
            raisonSociale: $raisonSociale,
            dateVisite: $dateVisite,
            annee: $annee,
            trigramme: $trigramme,
            contractEquipments: $contractEquipments,
            offContractEquipments: $offContractEquipments,
            medias: $medias,
        );

        $this->kizeoLogger->info('Données CR extraites', $extracted->toLogContext());

        return $extracted;
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
     * CORRECTION 30/01/2026:
     * Les noms de champs Kizeo sont différents des noms "logiques"
     * Mapping corrigé basé sur l'analyse du JSON réel
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

        // ==========================================================
        // MAPPING KIZEO CORRIGÉ (30/01/2026)
        // Basé sur l'analyse du JSON formulaire S40 (form_id 1055931)
        // ==========================================================
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
            // etat = Code état (A, B, C, D, E, F, G)
            etatEquipement: $this->getNestedValue($item, 'etat', 'value'),
            // Anomalies combinées
            anomalies: $this->extractAnomalies($item),
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

    /**
     * Extrait les anomalies concaténées depuis un équipement
     */
    private function extractAnomalies(array $item): ?string
    {
        $anomalies = [];

        // Les anomalies peuvent être dans différents champs
        $anomalyFields = [
            'anomalie_trigramme',
            'anomalies',
            'anomalie',
            'defaut',
            'observations',
        ];

        foreach ($anomalyFields as $field) {
            $value = $this->getNestedValue($item, $field, 'value');
            if ($value !== null && trim($value) !== '') {
                $anomalies[] = trim($value);
            }
        }

        return empty($anomalies) ? null : implode(' | ', $anomalies);
    }

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
     * CORRECTION 06/02/2026:
     * - Le type HC vient de "nature" (pas "type_equipement")
     * - Le code état HC vient de "etat1" (pas "etat")
     */
    private function parseOffContractEquipment(array $item, int $index): ?ExtractedEquipment
    {
        // FIX #3: Pour hors contrat, le type vient de "nature" (ex: "Rideau métallique")
        $typeEquipement = $this->getNestedValue($item, 'nature', 'value')
            ?? $this->getNestedValue($item, 'type_equipement', 'value')
            ?? $this->getNestedValue($item, 'type', 'value');

        return ExtractedEquipment::createOffContract(
            kizeoIndex: $index,
            typeEquipement: $typeEquipement,
            marque: $this->getNestedValue($item, 'marque', 'value'),
            // FIX: le code état HC est dans "etat1" (pas "etat")
            etatEquipement: $this->getNestedValue($item, 'etat1', 'value')
                ?? $this->getNestedValue($item, 'etat_equipement', 'value')
                ?? $this->getNestedValue($item, 'etat', 'value'),
            anomalies: $this->extractAnomalies($item),
            rawData: $item,
        );
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

            // Ignorer les images fixes du formulaire (icônes vert/orange/rouge)
            if ($fieldType === 'fixed_image') {
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