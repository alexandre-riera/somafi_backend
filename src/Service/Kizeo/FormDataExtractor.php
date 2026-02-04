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

        // 4. Extraire les médias (photos)
        $medias = $this->extractMedias($fields, $contractEquipments, $offContractEquipments);

        $extracted = new ExtractedFormData(
            formId: $formId,
            dataId: $dataId,
            idContact: $idContact,
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
     */
    private function parseOffContractEquipment(array $item, int $index): ?ExtractedEquipment
    {
        // Pour hors contrat, on prend tout - le numéro sera généré plus tard
        $typeEquipement = $this->getNestedValue($item, 'type_equipement', 'value')
            ?? $this->getNestedValue($item, 'type', 'value');

        return ExtractedEquipment::createOffContract(
            kizeoIndex: $index,
            typeEquipement: $typeEquipement,
            marque: $this->getNestedValue($item, 'marque', 'value'),
            etatEquipement: $this->getNestedValue($item, 'etat_equipement', 'value')
                ?? $this->getNestedValue($item, 'etat', 'value'),
            anomalies: $this->extractAnomalies($item),
            rawData: $item,
        );
    }

    /**
     * Extrait les médias (photos) depuis les champs du formulaire
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

        // 1. Photos des équipements au contrat
        $contractData = $fields['contrat_de_maintenance']['value'] ?? [];
        if (is_array($contractData)) {
            foreach ($contractData as $index => $item) {
                $equipmentNumero = $this->getNestedValue($item, 'equipement', 'value');
                if ($equipmentNumero === null) {
                    continue;
                }

                $this->extractEquipmentPhotos($item, $equipmentNumero, true, $medias);
            }
        }

        // 2. Photos des équipements hors contrat
        // Note: Le numéro sera assigné après génération dans EquipmentPersister
        $offContractData = $fields['tableau2']['value'] ?? [];
        if (is_array($offContractData)) {
            foreach ($offContractData as $index => $item) {
                // Pour hors contrat, on utilise un placeholder temporaire
                // Le numéro réel sera assigné par JobCreator après persistance
                $tempNumero = sprintf('HC_%d', $index);
                $this->extractEquipmentPhotos($item, $tempNumero, false, $medias);
            }
        }

        $this->kizeoLogger->debug('Médias extraits', [
            'count' => count($medias),
        ]);

        return $medias;
    }

    /**
     * Extrait les photos d'un équipement
     * 
     * @param array $equipmentData Données de l'équipement
     * @param string $equipmentNumero Numéro de l'équipement
     * @param bool $isContract Est-ce un équipement au contrat
     * @param ExtractedMedia[] &$medias Collection de médias (par référence)
     */
    private function extractEquipmentPhotos(
        array $equipmentData,
        string $equipmentNumero,
        bool $isContract,
        array &$medias
    ): void {
        // Champs photos connus dans le formulaire Kizeo
        $photoFields = [
            'photo_generale' => ExtractedMedia::PHOTO_TYPE_GENERALE,
            'photo_plaque' => ExtractedMedia::PHOTO_TYPE_PLAQUE,
            'photo_plaque_signaletique' => ExtractedMedia::PHOTO_TYPE_PLAQUE,
            'photo_environnement' => ExtractedMedia::PHOTO_TYPE_ENVIRONNEMENT,
            'photo_anomalie' => ExtractedMedia::PHOTO_TYPE_ANOMALIE,
            'photo_defaut' => ExtractedMedia::PHOTO_TYPE_ANOMALIE,
            'photo' => ExtractedMedia::PHOTO_TYPE_GENERALE,
        ];

        foreach ($photoFields as $fieldName => $photoType) {
            $mediaNames = $this->extractMediaNames($equipmentData, $fieldName);

            foreach ($mediaNames as $index => $mediaName) {
                if ($isContract) {
                    $medias[] = ExtractedMedia::createForContract(
                        mediaName: $mediaName,
                        equipmentNumero: $equipmentNumero,
                        photoType: $photoType,
                        photoIndex: count($mediaNames) > 1 ? $index + 1 : null,
                        fieldName: $fieldName,
                    );
                } else {
                    $medias[] = ExtractedMedia::createForOffContract(
                        mediaName: $mediaName,
                        equipmentNumero: $equipmentNumero,
                        fieldName: $fieldName,
                    );
                }
            }
        }
    }

    /**
     * Extrait les noms de médias depuis un champ
     * 
     * Le format peut être :
     * - String simple : "photo_1.jpg"
     * - Tableau : ["photo_1.jpg", "photo_2.jpg"]
     * 
     * @return string[]
     */
    private function extractMediaNames(array $data, string $fieldName): array
    {
        $value = $this->getNestedValue($data, $fieldName, 'value');

        if ($value === null || $value === '') {
            return [];
        }

        // Si c'est un tableau
        if (is_array($value)) {
            return array_filter($value, fn($v) => is_string($v) && $v !== '');
        }

        // Si c'est une string
        if (is_string($value)) {
            return [$value];
        }

        return [];
    }

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
