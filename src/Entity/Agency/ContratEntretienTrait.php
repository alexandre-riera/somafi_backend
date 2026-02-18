<?php

namespace App\Entity\Agency;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait contenant tous les champs communs aux 13 entités Contrat d'entretien
 * 
 * Utilisé par ContratS10, ContratS40, etc.
 * Mappé sur les tables contrat_sXX (13 agences)
 * 
 * @see SESSION_20260217_Recap - Phase 1 Fondations BDD
 */
trait ContratEntretienTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ──────────────────────────────────────────────────────────
    // INFORMATIONS GÉNÉRALES DU CONTRAT
    // ──────────────────────────────────────────────────────────

    #[ORM\Column]
    private ?int $numeroContrat = null;

    #[ORM\Column(length: 255)]
    private ?string $dateSignature = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dateDebutContrat = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateFinContrat = null;

    #[ORM\Column(length: 255)]
    private ?string $duree = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isTaciteReconduction = false;

    #[ORM\Column(length: 255)]
    private ?string $valorisation = null;

    #[ORM\Column(length: 255)]
    private ?string $statut = null;

    // ──────────────────────────────────────────────────────────
    // REVALORISATION
    // ──────────────────────────────────────────────────────────

    /**
     * Mode : pourcentage | gre_a_gre | presentiel
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $modeRevalorisation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $tauxRevalorisation = null;

    // ──────────────────────────────────────────────────────────
    // MONTANTS ET FACTURATION PAR VISITE
    // ──────────────────────────────────────────────────────────

    /**
     * Montant annuel HT = somme des montants de visites applicables
     * Champ dénormalisé, recalculé automatiquement par le service
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantAnnuelHt = null;

    #[ORM\Column(name: 'montant_visite_CEA', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantVisiteCEA = null;

    #[ORM\Column(name: 'montant_visite_CE1', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantVisiteCE1 = null;

    #[ORM\Column(name: 'montant_visite_CE2', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantVisiteCE2 = null;

    #[ORM\Column(name: 'montant_visite_CE3', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantVisiteCE3 = null;

    #[ORM\Column(name: 'montant_visite_CE4', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantVisiteCE4 = null;

    /**
     * Grille tarifaire par type d'équipement (JSON)
     * Ex: [{"type_equipement":"Porte sectionnelle","code":"SEC","prix_unitaire_ht":45.00}]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tarifsEquipements = null;

    // ──────────────────────────────────────────────────────────
    // ÉQUIPEMENTS ET VISITES
    // ──────────────────────────────────────────────────────────

    #[ORM\Column]
    private ?int $nombreEquipement = null;

    #[ORM\Column]
    private ?int $nombreVisite = null;

    // ──────────────────────────────────────────────────────────
    // DATES PRÉVISIONNELLES ET EFFECTIVES
    // ──────────────────────────────────────────────────────────

    #[ORM\Column(name: 'date_previsionnelle_1', length: 255, nullable: true)]
    private ?string $datePrevisionnelle1 = null;

    #[ORM\Column(name: 'date_previsionnelle_2', length: 255, nullable: true)]
    private ?string $datePrevisionnelle2 = null;

    #[ORM\Column(name: 'date_effective_1', length: 255, nullable: true)]
    private ?string $dateEffective1 = null;

    #[ORM\Column(name: 'date_effective_2', length: 255, nullable: true)]
    private ?string $dateEffective2 = null;

    // ──────────────────────────────────────────────────────────
    // RÉSILIATION
    // ──────────────────────────────────────────────────────────

    #[ORM\Column(length: 255)]
    private ?string $dateResiliation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifResiliation = null;

    // ──────────────────────────────────────────────────────────
    // DOCUMENTS ET NOTES
    // ──────────────────────────────────────────────────────────

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $contratPdfPath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // ──────────────────────────────────────────────────────────
    // LIAISON CLIENT
    // ──────────────────────────────────────────────────────────

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $idContact = null;

    // Note : contact_id (FK ManyToOne) et la relation OneToMany equipements
    // sont définis dans chaque entité concrète (ContratS10, ContratS40, etc.)
    // car ils pointent vers des entités spécifiques par agence.

    // ──────────────────────────────────────────────────────────
    // AUDIT / TRAÇABILITÉ
    // ──────────────────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // ══════════════════════════════════════════════════════════
    // GETTERS & SETTERS
    // ══════════════════════════════════════════════════════════

    public function getId(): ?int
    {
        return $this->id;
    }

    // --- Informations générales ---

    public function getNumeroContrat(): ?int
    {
        return $this->numeroContrat;
    }

    public function setNumeroContrat(int $numeroContrat): static
    {
        $this->numeroContrat = $numeroContrat;
        return $this;
    }

    public function getDateSignature(): ?string
    {
        return $this->dateSignature;
    }

    public function setDateSignature(string $dateSignature): static
    {
        $this->dateSignature = $dateSignature;
        return $this;
    }

    public function getDateDebutContrat(): ?\DateTimeInterface
    {
        return $this->dateDebutContrat;
    }

    public function setDateDebutContrat(\DateTimeInterface $dateDebutContrat): static
    {
        $this->dateDebutContrat = $dateDebutContrat;
        return $this;
    }

    public function getDateFinContrat(): ?\DateTimeInterface
    {
        return $this->dateFinContrat;
    }

    public function setDateFinContrat(?\DateTimeInterface $dateFinContrat): static
    {
        $this->dateFinContrat = $dateFinContrat;
        return $this;
    }

    public function getDuree(): ?string
    {
        return $this->duree;
    }

    public function setDuree(string $duree): static
    {
        $this->duree = $duree;
        return $this;
    }

    public function isTaciteReconduction(): bool
    {
        return $this->isTaciteReconduction;
    }

    public function setIsTaciteReconduction(bool $isTaciteReconduction): static
    {
        $this->isTaciteReconduction = $isTaciteReconduction;
        return $this;
    }

    public function getValorisation(): ?string
    {
        return $this->valorisation;
    }

    public function setValorisation(string $valorisation): static
    {
        $this->valorisation = $valorisation;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    // --- Revalorisation ---

    public function getModeRevalorisation(): ?string
    {
        return $this->modeRevalorisation;
    }

    public function setModeRevalorisation(?string $modeRevalorisation): static
    {
        $this->modeRevalorisation = $modeRevalorisation;
        return $this;
    }

    public function getTauxRevalorisation(): ?string
    {
        return $this->tauxRevalorisation;
    }

    public function setTauxRevalorisation(?string $tauxRevalorisation): static
    {
        $this->tauxRevalorisation = $tauxRevalorisation;
        return $this;
    }

    // --- Montants et facturation ---

    public function getMontantAnnuelHt(): ?string
    {
        return $this->montantAnnuelHt;
    }

    public function setMontantAnnuelHt(?string $montantAnnuelHt): static
    {
        $this->montantAnnuelHt = $montantAnnuelHt;
        return $this;
    }

    public function getMontantVisiteCEA(): ?string
    {
        return $this->montantVisiteCEA;
    }

    public function setMontantVisiteCEA(?string $montantVisiteCEA): static
    {
        $this->montantVisiteCEA = $montantVisiteCEA;
        return $this;
    }

    public function getMontantVisiteCE1(): ?string
    {
        return $this->montantVisiteCE1;
    }

    public function setMontantVisiteCE1(?string $montantVisiteCE1): static
    {
        $this->montantVisiteCE1 = $montantVisiteCE1;
        return $this;
    }

    public function getMontantVisiteCE2(): ?string
    {
        return $this->montantVisiteCE2;
    }

    public function setMontantVisiteCE2(?string $montantVisiteCE2): static
    {
        $this->montantVisiteCE2 = $montantVisiteCE2;
        return $this;
    }

    public function getMontantVisiteCE3(): ?string
    {
        return $this->montantVisiteCE3;
    }

    public function setMontantVisiteCE3(?string $montantVisiteCE3): static
    {
        $this->montantVisiteCE3 = $montantVisiteCE3;
        return $this;
    }

    public function getMontantVisiteCE4(): ?string
    {
        return $this->montantVisiteCE4;
    }

    public function setMontantVisiteCE4(?string $montantVisiteCE4): static
    {
        $this->montantVisiteCE4 = $montantVisiteCE4;
        return $this;
    }

    public function getTarifsEquipements(): ?array
    {
        return $this->tarifsEquipements;
    }

    public function setTarifsEquipements(?array $tarifsEquipements): static
    {
        $this->tarifsEquipements = $tarifsEquipements;
        return $this;
    }

    // --- Équipements et visites ---

    public function getNombreEquipement(): ?int
    {
        return $this->nombreEquipement;
    }

    public function setNombreEquipement(int $nombreEquipement): static
    {
        $this->nombreEquipement = $nombreEquipement;
        return $this;
    }

    public function getNombreVisite(): ?int
    {
        return $this->nombreVisite;
    }

    public function setNombreVisite(int $nombreVisite): static
    {
        $this->nombreVisite = $nombreVisite;
        return $this;
    }

    // --- Dates prévisionnelles et effectives ---

    public function getDatePrevisionnelle1(): ?string
    {
        return $this->datePrevisionnelle1;
    }

    public function setDatePrevisionnelle1(?string $datePrevisionnelle1): static
    {
        $this->datePrevisionnelle1 = $datePrevisionnelle1;
        return $this;
    }

    public function getDatePrevisionnelle2(): ?string
    {
        return $this->datePrevisionnelle2;
    }

    public function setDatePrevisionnelle2(?string $datePrevisionnelle2): static
    {
        $this->datePrevisionnelle2 = $datePrevisionnelle2;
        return $this;
    }

    public function getDateEffective1(): ?string
    {
        return $this->dateEffective1;
    }

    public function setDateEffective1(?string $dateEffective1): static
    {
        $this->dateEffective1 = $dateEffective1;
        return $this;
    }

    public function getDateEffective2(): ?string
    {
        return $this->dateEffective2;
    }

    public function setDateEffective2(?string $dateEffective2): static
    {
        $this->dateEffective2 = $dateEffective2;
        return $this;
    }

    // --- Résiliation ---

    public function getDateResiliation(): ?string
    {
        return $this->dateResiliation;
    }

    public function setDateResiliation(string $dateResiliation): static
    {
        $this->dateResiliation = $dateResiliation;
        return $this;
    }

    public function getMotifResiliation(): ?string
    {
        return $this->motifResiliation;
    }

    public function setMotifResiliation(?string $motifResiliation): static
    {
        $this->motifResiliation = $motifResiliation;
        return $this;
    }

    // --- Documents et notes ---

    public function getContratPdfPath(): ?string
    {
        return $this->contratPdfPath;
    }

    public function setContratPdfPath(?string $contratPdfPath): static
    {
        $this->contratPdfPath = $contratPdfPath;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    // --- Liaison client ---

    public function getIdContact(): ?string
    {
        return $this->idContact;
    }

    public function setIdContact(?string $idContact): static
    {
        $this->idContact = $idContact;
        return $this;
    }

    // --- Audit ---

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    // ══════════════════════════════════════════════════════════
    // MÉTHODES MÉTIER
    // ══════════════════════════════════════════════════════════

    /**
     * Détermine si le contrat est actif
     */
    public function isActif(): bool
    {
        return $this->statut === 'actif';
    }

    /**
     * Détermine si le contrat est résilié
     */
    public function isResilie(): bool
    {
        return $this->statut === 'resilie';
    }

    /**
     * Calcule le montant annuel HT à partir des montants par visite
     * selon le nombre de visites du contrat.
     * 
     * 1 visite/an  → CEA
     * 2 visites/an → CE1 + CE2
     * 3 visites/an → CE1 + CE2 + CE3
     * 4 visites/an → CE1 + CE2 + CE3 + CE4
     */
    public function calculerMontantAnnuelHt(): string
    {
        $montant = '0.00';

        if ($this->nombreVisite === 1) {
            $montant = $this->montantVisiteCEA ?? '0.00';
        } elseif ($this->nombreVisite >= 2) {
            $total = 0;
            $total += (float) ($this->montantVisiteCE1 ?? 0);
            $total += (float) ($this->montantVisiteCE2 ?? 0);

            if ($this->nombreVisite >= 3) {
                $total += (float) ($this->montantVisiteCE3 ?? 0);
            }
            if ($this->nombreVisite >= 4) {
                $total += (float) ($this->montantVisiteCE4 ?? 0);
            }

            $montant = number_format($total, 2, '.', '');
        }

        $this->montantAnnuelHt = $montant;
        return $montant;
    }

    /**
     * Retourne la grille tarifaire sous forme de tableau associatif
     * indexé par code équipement
     * 
     * @return array<string, array{type_equipement: string, code: string, prix_unitaire_ht: float}>
     */
    public function getTarifsParCode(): array
    {
        if (empty($this->tarifsEquipements)) {
            return [];
        }

        $result = [];
        foreach ($this->tarifsEquipements as $tarif) {
            if (isset($tarif['code'])) {
                $result[$tarif['code']] = $tarif;
            }
        }

        return $result;
    }

    /**
     * Lifecycle callback - initialise createdAt à la création
     */
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    /**
     * Lifecycle callback - met à jour updatedAt à chaque modification
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
