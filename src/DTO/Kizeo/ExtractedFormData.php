<?php

declare(strict_types=1);

namespace App\DTO\Kizeo;

/**
 * DTO contenant les données extraites d'un CR Technicien Kizeo
 * 
 * Représente les informations globales d'un formulaire (data) :
 * - Identifiants Kizeo (formId, dataId)
 * - Contexte client (idContact, idSociete, raisonSociale)
 * - Contexte visite (date, année, trigramme technicien)
 * - Collections d'équipements et médias
 */
final class ExtractedFormData
{
    /**
     * @param int $formId ID du formulaire Kizeo
     * @param int $dataId ID des données Kizeo (unique par soumission)
     * @param int|null $idContact ID du contact SOMAFI (clé de liaison)
     * @param string|null $idSociete ID société (référence client)
     * @param string|null $raisonSociale Nom du client
     * @param \DateTimeInterface|null $dateVisite Date de la visite
     * @param string|null $annee Année de la visite (YYYY)
     * @param string|null $trigramme Trigramme du technicien
     * @param ExtractedEquipment[] $contractEquipments Équipements au contrat
     * @param ExtractedEquipment[] $offContractEquipments Équipements hors contrat
     * @param ExtractedMedia[] $medias Photos à télécharger
     */
    public function __construct(
        public readonly int $formId,
        public readonly int $dataId,
        public readonly ?int $idContact,
        public readonly ?string $idSociete,
        public readonly ?string $raisonSociale,
        public readonly ?\DateTimeInterface $dateVisite,
        public readonly ?string $annee,
        public readonly ?string $trigramme,
        public readonly array $contractEquipments = [],
        public readonly array $offContractEquipments = [],
        public readonly array $medias = [],
    ) {
    }

    /**
     * Vérifie si les données essentielles sont présentes
     */
    public function isValid(): bool
    {
        return $this->idContact !== null 
            && $this->annee !== null 
            && strlen($this->annee) === 4;
    }

    /**
     * Retourne le nombre total d'équipements
     */
    public function getTotalEquipments(): int
    {
        return count($this->contractEquipments) + count($this->offContractEquipments);
    }

    /**
     * Vérifie si le CR contient des équipements
     */
    public function hasEquipments(): bool
    {
        return $this->getTotalEquipments() > 0;
    }

    /**
     * Vérifie si le CR contient des médias
     */
    public function hasMedias(): bool
    {
        return count($this->medias) > 0;
    }

    /**
     * Retourne le nom client sanitizé pour les noms de fichiers
     */
    public function getSanitizedClientName(): string
    {
        if ($this->raisonSociale === null) {
            return 'INCONNU';
        }

        // Remplacer les caractères spéciaux par underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->raisonSociale);
        
        // Supprimer les underscores multiples
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        
        // Limiter la longueur
        return strtoupper(substr(trim($sanitized, '_'), 0, 50));
    }

    /**
     * Crée une représentation pour les logs
     */
    public function toLogContext(): array
    {
        return [
            'form_id' => $this->formId,
            'data_id' => $this->dataId,
            'id_contact' => $this->idContact,
            'id_societe' => $this->idSociete,
            'client' => $this->raisonSociale,
            'date_visite' => $this->dateVisite?->format('Y-m-d'),
            'annee' => $this->annee,
            'technicien' => $this->trigramme,
            'nb_contract_equipments' => count($this->contractEquipments),
            'nb_offcontract_equipments' => count($this->offContractEquipments),
            'nb_medias' => count($this->medias),
        ];
    }
}