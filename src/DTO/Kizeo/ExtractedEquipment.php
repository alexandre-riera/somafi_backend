<?php

declare(strict_types=1);

namespace App\DTO\Kizeo;

/**
 * DTO représentant un équipement extrait du JSON Kizeo
 * 
 * Utilisé pour les équipements au contrat (contrat_de_maintenance)
 * et hors contrat (tableau2), avec des champs spécifiques à chaque type.
 * 
 * CORRECTION 06/02/2026:
 * - Ajout statutEquipement (code lettre A-G) distinct de etatEquipement (description)
 * - Ajout observations (travaux/pièces remplacées, temps estimé, nacelle)
 * - Enrichissement de createOffContract() avec TOUS les champs HC
 *   (repère, dimensions, mode fonctionnement, mise en service, n° série, libellé)
 */
final class ExtractedEquipment
{
    public const TYPE_CONTRACT = 'contract';
    public const TYPE_OFF_CONTRACT = 'off_contract';

    /**
     * @param string $type Type d'équipement (contract ou off_contract)
     * @param string|null $numeroEquipement Numéro unique (ex: SEC03, RAP01)
     * @param string|null $visite Type de visite (CE1, CE2, CE3, CE4, CEA)
     * @param string|null $libelleEquipement Libellé/description
     * @param string|null $typeEquipement Type (porte sectionnelle, rideau, etc.)
     * @param string|null $marque Marque de l'équipement
     * @param string|null $modeFonctionnement Mode de fonctionnement
     * @param string|null $repereSiteClient Repère sur site client
     * @param string|null $miseEnService Année de mise en service
     * @param string|null $numeroSerie Numéro de série
     * @param string|null $hauteur Hauteur (en mm ou cm)
     * @param string|null $largeur Largeur
     * @param string|null $longueur Longueur/Profondeur
     * @param string|null $statutEquipement Code lettre statut (A, B, C, D, E, F, G)
     * @param string|null $etatEquipement Description textuelle de l'état
     * @param string|null $anomalies Anomalies relevées
     * @param string|null $observations Observations (travaux, pièces, temps estimé)
     * @param int|null $kizeoIndex Index dans le tableau JSON (hors contrat uniquement)
     * @param array $rawData Données brutes du JSON pour debug
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $numeroEquipement,
        public readonly ?string $visite,
        public readonly ?string $libelleEquipement = null,
        public readonly ?string $typeEquipement = null,
        public readonly ?string $marque = null,
        public readonly ?string $modeFonctionnement = null,
        public readonly ?string $repereSiteClient = null,
        public readonly ?string $miseEnService = null,
        public readonly ?string $numeroSerie = null,
        public readonly ?string $hauteur = null,
        public readonly ?string $largeur = null,
        public readonly ?string $longueur = null,
        public readonly ?string $statutEquipement = null,
        public readonly ?string $etatEquipement = null,
        public readonly ?string $anomalies = null,
        public readonly ?string $observations = null,
        public readonly ?int $kizeoIndex = null,
        public readonly array $rawData = [],
    ) {
    }

    /**
     * Vérifie si c'est un équipement au contrat
     */
    public function isContract(): bool
    {
        return $this->type === self::TYPE_CONTRACT;
    }

    /**
     * Vérifie si c'est un équipement hors contrat
     */
    public function isOffContract(): bool
    {
        return $this->type === self::TYPE_OFF_CONTRACT;
    }

    /**
     * Vérifie si le numéro d'équipement est valide
     */
    public function hasValidNumero(): bool
    {
        return $this->numeroEquipement !== null 
            && trim($this->numeroEquipement) !== '';
    }

    /**
     * Vérifie si la visite est valide
     */
    public function hasValidVisite(): bool
    {
        if ($this->visite === null) {
            return false;
        }

        // Visites valides : CE1, CE2, CE3, CE4, CEA
        return (bool) preg_match('/^CE[A1-4]$/i', $this->visite);
    }

    /**
     * Retourne la visite normalisée en majuscules
     */
    public function getNormalizedVisite(): ?string
    {
        return $this->visite !== null ? strtoupper(trim($this->visite)) : null;
    }

    /**
     * Génère une clé de déduplication pour équipement au contrat
     * Clé : numero_equipement + visite + année (passée en paramètre)
     */
    public function getContractDeduplicationKey(string $annee): string
    {
        return sprintf(
            '%s|%s|%s',
            strtoupper(trim($this->numeroEquipement ?? '')),
            strtoupper(trim($this->visite ?? '')),
            $annee
        );
    }

    /**
     * Génère une clé de déduplication pour équipement hors contrat
     * Clé : kizeo_form_id + kizeo_data_id + kizeo_index
     */
    public function getOffContractDeduplicationKey(int $formId, int $dataId): string
    {
        return sprintf(
            '%d|%d|%d',
            $formId,
            $dataId,
            $this->kizeoIndex ?? 0
        );
    }

    /**
     * Extrait le préfixe du type d'équipement pour la génération de numéro
     * 
     * CORRECTION 06/02/2026: Mapping étendu avec les mêmes entrées que
     * KizeoFormProcessor::deduceTypePrefixFromNature() pour cohérence.
     */
    public function getTypePrefix(): string
    {
        if ($this->typeEquipement === null) {
            return 'EQU';
        }

        $type = mb_strtolower(trim($this->typeEquipement));

        // IMPORTANT: ordonné du plus spécifique au plus générique
        $prefixes = [
            'porte sectionnelle' => 'SEC',
            'sectionnelle' => 'SEC',
            'porte rapide' => 'RAP',
            'porte automatique' => 'RAP',
            'rapide' => 'RAP',
            'rideau métallique' => 'RID',
            'rideau metallique' => 'RID',
            'rideau' => 'RID',
            'portail coulissant' => 'PAU',
            'portail battant' => 'PMO',
            'portail manuel' => 'PMA',
            'portail' => 'PAU',
            'barrière levante' => 'BLE',
            'barriere levante' => 'BLE',
            'barrière' => 'BLE',
            'barriere' => 'BLE',
            'niveleur de quai' => 'NIV',
            'niveleur' => 'NIV',
            'quai' => 'NIV',
            'porte coupe-feu' => 'CFE',
            'porte coupe feu' => 'CFE',
            'coupe-feu' => 'CFE',
            'coupe feu' => 'CFE',
            'porte piétonne' => 'PPV',
            'porte pieton' => 'PPV',
            'porte piéton' => 'PPV',
            'pieton' => 'PPI',
            'tourniquet' => 'TOU',
            'sas' => 'SAS',
            'bloc-roue' => 'BRO',
            'bloc roue' => 'BRO',
            'table elevatrice' => 'TEL',
            'butoir' => 'BUT',
            'buttoir' => 'BUT',
            'automatique' => 'PAU',
        ];

        foreach ($prefixes as $keyword => $prefix) {
            if (str_contains($type, $keyword)) {
                return $prefix;
            }
        }

        return 'EQU';
    }

    /**
     * Crée une représentation pour les logs
     */
    public function toLogContext(): array
    {
        return [
            'type' => $this->type,
            'numero' => $this->numeroEquipement,
            'visite' => $this->visite,
            'libelle' => $this->libelleEquipement,
            'type_equipement' => $this->typeEquipement,
            'statut' => $this->statutEquipement,
            'etat' => $this->etatEquipement,
            'anomalies' => $this->anomalies,
            'observations' => $this->observations,
            'kizeo_index' => $this->kizeoIndex,
        ];
    }

    /**
     * Factory pour équipement au contrat
     */
    public static function createContract(
        string $numeroEquipement,
        string $visite,
        ?string $libelleEquipement = null,
        ?string $typeEquipement = null,
        ?string $marque = null,
        ?string $modeFonctionnement = null,
        ?string $repereSiteClient = null,
        ?string $miseEnService = null,
        ?string $numeroSerie = null,
        ?string $hauteur = null,
        ?string $largeur = null,
        ?string $longueur = null,
        ?string $statutEquipement = null,
        ?string $etatEquipement = null,
        ?string $anomalies = null,
        ?string $observations = null,
        array $rawData = [],
    ): self {
        return new self(
            type: self::TYPE_CONTRACT,
            numeroEquipement: $numeroEquipement,
            visite: $visite,
            libelleEquipement: $libelleEquipement,
            typeEquipement: $typeEquipement,
            marque: $marque,
            modeFonctionnement: $modeFonctionnement,
            repereSiteClient: $repereSiteClient,
            miseEnService: $miseEnService,
            numeroSerie: $numeroSerie,
            hauteur: $hauteur,
            largeur: $largeur,
            longueur: $longueur,
            statutEquipement: $statutEquipement,
            etatEquipement: $etatEquipement,
            anomalies: $anomalies,
            observations: $observations,
            rawData: $rawData,
        );
    }

    /**
     * Factory pour équipement hors contrat
     * 
     * CORRECTION 06/02/2026:
     * Enrichi avec TOUS les champs HC (repère, dimensions, mode fonctionnement,
     * mise en service, n° série, libellé, statut, observations).
     * Avant, seuls typeEquipement, marque, etatEquipement et anomalies étaient passés.
     */
    public static function createOffContract(
        int $kizeoIndex,
        ?string $typeEquipement = null,
        ?string $libelleEquipement = null,
        ?string $marque = null,
        ?string $modeFonctionnement = null,
        ?string $repereSiteClient = null,
        ?string $miseEnService = null,
        ?string $numeroSerie = null,
        ?string $hauteur = null,
        ?string $largeur = null,
        ?string $statutEquipement = null,
        ?string $etatEquipement = null,
        ?string $anomalies = null,
        ?string $observations = null,
        array $rawData = [],
    ): self {
        return new self(
            type: self::TYPE_OFF_CONTRACT,
            numeroEquipement: null, // Sera généré plus tard par EquipmentPersister
            visite: null, // N/A pour hors contrat
            libelleEquipement: $libelleEquipement ?? $typeEquipement ?? 'Équipement HC',
            typeEquipement: $typeEquipement,
            marque: $marque,
            modeFonctionnement: $modeFonctionnement,
            repereSiteClient: $repereSiteClient,
            miseEnService: $miseEnService,
            numeroSerie: $numeroSerie,
            hauteur: $hauteur,
            largeur: $largeur,
            longueur: null, // Pas de champ longueur en HC
            statutEquipement: $statutEquipement,
            etatEquipement: $etatEquipement,
            anomalies: $anomalies,
            observations: $observations,
            kizeoIndex: $kizeoIndex,
            rawData: $rawData,
        );
    }
}