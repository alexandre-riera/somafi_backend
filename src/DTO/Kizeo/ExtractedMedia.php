<?php

declare(strict_types=1);

namespace App\DTO\Kizeo;

/**
 * DTO représentant un média (photo) à télécharger depuis Kizeo
 * 
 * Contient les informations nécessaires pour :
 * - Télécharger le fichier via l'API Kizeo
 * - Construire le chemin de stockage local
 * - Nommer le fichier correctement
 */
final class ExtractedMedia
{
    // Types de photos reconnus
    public const PHOTO_TYPE_GENERALE = 'generale';
    public const PHOTO_TYPE_PLAQUE = 'plaque';
    public const PHOTO_TYPE_ENVIRONNEMENT = 'environnement';
    public const PHOTO_TYPE_ANOMALIE = 'anomalie';
    public const PHOTO_TYPE_COMPTE_RENDU = 'compte_rendu';
    public const PHOTO_TYPE_AUTRE = 'autre';

    /**
     * @param string $mediaName Nom du fichier sur Kizeo (ex: photo_generale_1.jpg)
     * @param string|null $equipmentNumero Numéro de l'équipement associé
     * @param string $photoType Type de photo (generale, plaque, etc.)
     * @param int|null $photoIndex Index si plusieurs photos du même type
     * @param string|null $fieldName Nom du champ Kizeo d'origine
     * @param bool $isContract Photo d'équipement au contrat ou hors contrat
     * @param int|null $equipmentIndex Index de l'équipement dans le tableau Kizeo (hors contrat)
     */
    public function __construct(
        public readonly string $mediaName,
        public readonly ?string $equipmentNumero,
        public readonly string $photoType = self::PHOTO_TYPE_AUTRE,
        public readonly ?int $photoIndex = null,
        public readonly ?string $fieldName = null,
        public readonly bool $isContract = true,
        public readonly ?int $equipmentIndex = null,
    ) {
    }

    /**
     * Génère le nom de fichier local standardisé
     * 
     * Format équipement au contrat : {numero}_{type}.jpg
     * Format hors contrat : {numero}_compte_rendu.jpg
     * 
     * Exemples :
     * - SEC03_generale.jpg
     * - RAP01_plaque.jpg
     * - SEC04_compte_rendu.jpg (hors contrat)
     */
    public function getLocalFileName(): string
    {
        $numero = $this->equipmentNumero ?? 'UNKNOWN';
        $type = $this->photoType;

        // Si plusieurs photos du même type, ajouter l'index
        if ($this->photoIndex !== null && $this->photoIndex > 1) {
            return sprintf('%s_%s_%d.jpg', $numero, $type, $this->photoIndex);
        }

        return sprintf('%s_%s.jpg', $numero, $type);
    }

    /**
     * Génère le chemin complet de stockage
     * 
     * Structure : /storage/img/{agency}/{id_contact}/{annee}/{visite}/{filename}
     */
    public function getLocalPath(
        string $basePath,
        string $agencyCode,
        int $idContact,
        string $annee,
        string $visite
    ): string {
        return sprintf(
            '%s/img/%s/%d/%s/%s/%s',
            rtrim($basePath, '/'),
            $agencyCode,
            $idContact,
            $annee,
            strtoupper($visite),
            $this->getLocalFileName()
        );
    }

    /**
     * Extrait l'extension du fichier original
     */
    public function getOriginalExtension(): string
    {
        $ext = pathinfo($this->mediaName, PATHINFO_EXTENSION);
        return strtolower($ext) ?: 'jpg';
    }

    /**
     * Vérifie si c'est une image valide (par extension)
     */
    public function isValidImage(): bool
    {
        $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        return in_array($this->getOriginalExtension(), $validExtensions, true);
    }

    /**
     * Crée une représentation pour les logs
     */
    public function toLogContext(): array
    {
        return [
            'media_name' => $this->mediaName,
            'equipment_numero' => $this->equipmentNumero,
            'photo_type' => $this->photoType,
            'photo_index' => $this->photoIndex,
            'field_name' => $this->fieldName,
            'is_contract' => $this->isContract,
            'equipment_index' => $this->equipmentIndex,
            'local_filename' => $this->getLocalFileName(),
        ];
    }

    /**
     * Factory pour photo d'équipement au contrat
     */
    public static function createForContract(
        string $mediaName,
        string $equipmentNumero,
        string $photoType,
        ?int $photoIndex = null,
        ?string $fieldName = null,
    ): self {
        return new self(
            mediaName: $mediaName,
            equipmentNumero: $equipmentNumero,
            photoType: self::normalizePhotoType($photoType),
            photoIndex: $photoIndex,
            fieldName: $fieldName,
            isContract: true,
            equipmentIndex: null,
        );
    }

    /**
     * Factory pour photo d'équipement hors contrat
     * 
     * @param int|null $equipmentIndex Index de l'équipement dans tableau2 (pour liaison avec generatedNumbers)
     */
    public static function createForOffContract(
        string $mediaName,
        string $equipmentNumero,
        ?string $fieldName = null,
        ?int $equipmentIndex = null,
    ): self {
        return new self(
            mediaName: $mediaName,
            equipmentNumero: $equipmentNumero,
            photoType: self::PHOTO_TYPE_COMPTE_RENDU,
            photoIndex: null,
            fieldName: $fieldName,
            isContract: false,
            equipmentIndex: $equipmentIndex,
        );
    }

    /**
     * Normalise le type de photo depuis le nom du champ Kizeo
     */
    public static function normalizePhotoType(string $fieldNameOrType): string
    {
        $lower = strtolower(trim($fieldNameOrType));

        // Mapping des noms de champs Kizeo vers types standards
        $mappings = [
            'photo_generale' => self::PHOTO_TYPE_GENERALE,
            'generale' => self::PHOTO_TYPE_GENERALE,
            'general' => self::PHOTO_TYPE_GENERALE,
            'photo_plaque' => self::PHOTO_TYPE_PLAQUE,
            'plaque' => self::PHOTO_TYPE_PLAQUE,
            'plaque_signaletique' => self::PHOTO_TYPE_PLAQUE,
            'photo_environnement' => self::PHOTO_TYPE_ENVIRONNEMENT,
            'environnement' => self::PHOTO_TYPE_ENVIRONNEMENT,
            'environment' => self::PHOTO_TYPE_ENVIRONNEMENT,
            'photo_anomalie' => self::PHOTO_TYPE_ANOMALIE,
            'anomalie' => self::PHOTO_TYPE_ANOMALIE,
            'defaut' => self::PHOTO_TYPE_ANOMALIE,
            'compte_rendu' => self::PHOTO_TYPE_COMPTE_RENDU,
            'cr' => self::PHOTO_TYPE_COMPTE_RENDU,
        ];

        foreach ($mappings as $keyword => $type) {
            if (str_contains($lower, $keyword)) {
                return $type;
            }
        }

        return self::PHOTO_TYPE_AUTRE;
    }

    /**
     * Génère une clé unique pour éviter les doublons
     * Utilisée dans KizeoJobRepository
     */
    public function getDeduplicationKey(int $formId, int $dataId): string
    {
        return sprintf('%d|%d|%s', $formId, $dataId, $this->mediaName);
    }
}