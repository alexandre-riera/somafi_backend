<?php

declare(strict_types=1);

namespace App\DTO\Kizeo;

/**
 * DTO représentant un équipement extrait du JSON Kizeo
 * 
 * Utilisé pour les équipements au contrat (contrat_de_maintenance)
 * et hors contrat (tableau2), avec des champs spécifiques à chaque type.
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
     * @param string|null $etatEquipement État (Bon/Moyen/Mauvais)
     * @param string|null $anomalies Anomalies relevées
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
        public readonly ?string $etatEquipement = null,
        public readonly ?string $anomalies = null,
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
     */
    public function getTypePrefix(): string
    {
        if ($this->typeEquipement === null) {
            return 'EQU';
        }

        $type = strtolower(trim($this->typeEquipement));

        $prefixes = [
            'porte sectionnelle' => 'SEC',
            'sectionnelle' => 'SEC',
            'porte rapide' => 'RAP',
            'rapide' => 'RAP',
            'rideau metallique' => 'RID',
            'rideau métallique' => 'RID',
            'rideau' => 'RID',
            'portail' => 'POR',
            'barriere' => 'BAR',
            'barrière' => 'BAR',
            'niveleur de quai' => 'NIV',
            'niveleur' => 'NIV',
            'quai' => 'NIV',
            'porte coupe feu' => 'CFE',
            'coupe feu' => 'CFE',
            'coupe-feu' => 'CFE',
            'porte automatique' => 'PAU',
            'automatique' => 'PAU',
            'porte pieton' => 'PPI',
            'porte piéton' => 'PPI',
            'pieton' => 'PPI',
            'tourniquet' => 'TRN',
            'sas' => 'SAS',
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
            'type_equipement' => $this->typeEquipement,
            'etat' => $this->etatEquipement,
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
        ?string $etatEquipement = null,
        ?string $anomalies = null,
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
            etatEquipement: $etatEquipement,
            anomalies: $anomalies,
            rawData: $rawData,
        );
    }

    /**
     * Factory pour équipement hors contrat
     */
    public static function createOffContract(
        int $kizeoIndex,
        ?string $typeEquipement = null,
        ?string $marque = null,
        ?string $etatEquipement = null,
        ?string $anomalies = null,
        array $rawData = [],
    ): self {
        return new self(
            type: self::TYPE_OFF_CONTRACT,
            numeroEquipement: null, // Sera généré plus tard
            visite: null, // N/A pour hors contrat
            typeEquipement: $typeEquipement,
            marque: $marque,
            etatEquipement: $etatEquipement,
            anomalies: $anomalies,
            kizeoIndex: $kizeoIndex,
            rawData: $rawData,
        );
    }
}
