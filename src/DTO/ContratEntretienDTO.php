<?php

namespace App\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

class ContratEntretienDTO
{
    // --- Informations générales ---

    #[Assert\NotBlank(message: 'Le numéro de contrat est obligatoire.')]
    #[Assert\Positive(message: 'Le numéro doit être positif.')]
    public ?int $numeroContrat = null;

    #[Assert\NotBlank(message: 'La date de signature est obligatoire.')]
    public ?\DateTimeInterface $dateSignature = null;

    #[Assert\NotBlank(message: 'La date de début est obligatoire.')]
    public ?\DateTimeInterface $dateDebutContrat = null;

    public ?\DateTimeInterface $dateFinContrat = null;

    #[Assert\NotBlank(message: 'La durée est obligatoire.')]
    public ?string $duree = null;

    public bool $isTaciteReconduction = false;

    // --- Financier ---

    public ?string $valorisation = null;

    public ?string $modeRevalorisation = null;

    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Le taux doit être entre 0 et 100%.')]
    public ?float $tauxRevalorisation = null;

    #[Assert\PositiveOrZero(message: 'Le montant doit être positif ou zéro.')]
    public ?float $montantAnnuelHt = null;

    #[Assert\PositiveOrZero]
    public ?float $montantVisiteCEA = null;

    #[Assert\PositiveOrZero]
    public ?float $montantVisiteCE1 = null;

    #[Assert\PositiveOrZero]
    public ?float $montantVisiteCE2 = null;

    #[Assert\PositiveOrZero]
    public ?float $montantVisiteCE3 = null;

    #[Assert\PositiveOrZero]
    public ?float $montantVisiteCE4 = null;

    // --- Parc ---

    #[Assert\NotBlank(message: 'Le nombre d\'équipements est obligatoire.')]
    #[Assert\PositiveOrZero]
    public ?int $nombreEquipement = 0;

    #[Assert\NotBlank(message: 'Le nombre de visites est obligatoire.')]
    #[Assert\Positive(message: 'Il faut au moins 1 visite.')]
    public ?int $nombreVisite = null;

    // --- Planification ---

    public ?string $datePrevisionnelle1 = null;
    public ?string $datePrevisionnelle2 = null;

    // --- Documents ---

    #[Assert\File(
        maxSize: '10M',
        mimeTypes: ['application/pdf'],
        mimeTypesMessage: 'Seuls les fichiers PDF sont acceptés.',
        maxSizeMessage: 'Le fichier ne doit pas dépasser 10 Mo.'
    )]
    public ?UploadedFile $contratPdfFile = null;

    // --- Notes ---

    public ?string $notes = null;

    // --- Liaison client (hidden fields) ---

    #[Assert\NotBlank(message: 'Le client (id_contact) est obligatoire.')]
    public ?string $idContact = null;

    #[Assert\NotBlank(message: 'Le client (contact_id) est obligatoire.')]
    public ?int $contactId = null;

    /**
     * Convertit le DTO en array pour insertion DBAL.
     * Le champ contrat_pdf_path est géré séparément par le service PDF.
     */
    public function toArray(): array
    {
        $data = [
            'numero_contrat' => $this->numeroContrat,
            'date_signature' => $this->dateSignature?->format('Y-m-d'),
            'date_debut_contrat' => $this->dateDebutContrat?->format('Y-m-d'),
            'date_fin_contrat' => $this->dateFinContrat?->format('Y-m-d'),
            'duree' => $this->duree,
            'is_tacite_reconduction' => $this->isTaciteReconduction ? 1 : 0,
            'valorisation' => $this->valorisation,
            'mode_revalorisation' => $this->modeRevalorisation,
            'taux_revalorisation' => $this->tauxRevalorisation,
            'montant_annuel_ht' => $this->montantAnnuelHt,
            'montant_visite_CEA' => $this->montantVisiteCEA,
            'montant_visite_CE1' => $this->montantVisiteCE1,
            'montant_visite_CE2' => $this->montantVisiteCE2,
            'montant_visite_CE3' => $this->montantVisiteCE3,
            'montant_visite_CE4' => $this->montantVisiteCE4,
            'nombre_equipement' => $this->nombreEquipement ?? 0,
            'nombre_visite' => $this->nombreVisite,
            'date_previsionnelle_1' => $this->datePrevisionnelle1,
            'date_previsionnelle_2' => $this->datePrevisionnelle2,
            'notes' => $this->notes,
            'id_contact' => $this->idContact,
            'contact_id' => $this->contactId,
            'statut' => 'actif',
            'date_resiliation' => '',
        ];

        // Filtrer les valeurs nulles (sauf les champs NOT NULL avec défaut vide)
        return array_filter($data, fn($v) => $v !== null);
    }

    /**
     * Hydrate un DTO depuis une ligne BDD (contrat_sXX).
     * Utilisé par le formulaire d'édition pour pré-remplir les champs.
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->numeroContrat        = isset($data['numero_contrat']) ? (int) $data['numero_contrat'] : null;
        $dto->dateSignature = isset($data['date_signature'])? (\DateTime::createFromFormat('d/m/Y', $data['date_signature']) ?: new \DateTime($data['date_signature'])): null;
        $dto->dateDebutContrat     = isset($data['date_debut_contrat']) ? new \DateTime($data['date_debut_contrat']) : null;
        $dto->dateFinContrat       = isset($data['date_fin_contrat']) ? new \DateTime($data['date_fin_contrat']) : null;
        $dto->duree                = $data['duree'] ?? null;
        $dto->isTaciteReconduction = (bool) ($data['is_tacite_reconduction'] ?? false);

        // Financier
        $dto->valorisation         = $data['valorisation'] ?? 'forfait';
        $dto->modeRevalorisation   = $data['mode_revalorisation'] ?? null;
        $dto->tauxRevalorisation   = isset($data['taux_revalorisation']) ? (float) $data['taux_revalorisation'] : null;
        $dto->montantAnnuelHt      = isset($data['montant_annuel_ht']) ? (float) $data['montant_annuel_ht'] : null;
        $dto->montantVisiteCEA     = isset($data['montant_visite_CEA']) ? (float) $data['montant_visite_CEA'] : null;
        $dto->montantVisiteCE1     = isset($data['montant_visite_CE1']) ? (float) $data['montant_visite_CE1'] : null;
        $dto->montantVisiteCE2     = isset($data['montant_visite_CE2']) ? (float) $data['montant_visite_CE2'] : null;
        $dto->montantVisiteCE3     = isset($data['montant_visite_CE3']) ? (float) $data['montant_visite_CE3'] : null;
        $dto->montantVisiteCE4     = isset($data['montant_visite_CE4']) ? (float) $data['montant_visite_CE4'] : null;

        // Planification
        $dto->nombreEquipement     = isset($data['nombre_equipement']) ? (int) $data['nombre_equipement'] : 0;
        $dto->nombreVisite         = isset($data['nombre_visite']) ? (int) $data['nombre_visite'] : null;
        $dto->datePrevisionnelle1  = $data['date_previsionnelle_1'] ?? null;
        $dto->datePrevisionnelle2  = $data['date_previsionnelle_2'] ?? null;

        // Notes
        $dto->notes                = $data['notes'] ?? null;

        // Liaison client (hidden fields)
        $dto->idContact            = $data['id_contact'] ?? null;
        $dto->contactId            = isset($data['contact_id']) ? (int) $data['contact_id'] : null;

        return $dto;
    }
}