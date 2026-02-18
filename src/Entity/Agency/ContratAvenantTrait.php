<?php

namespace App\Entity\Agency;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait contenant tous les champs communs aux 13 entités ContratAvenant
 * 
 * Utilisé par ContratAvenantS10, ContratAvenantS40, etc.
 * Mappé sur les tables contrat_avenant_sXX (13 agences)
 * 
 * @see SESSION_20260217_Recap - Phase 1 Fondations BDD
 */
trait ContratAvenantTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Numéro séquentiel de l'avenant (AV-001, AV-002, etc.)
     */
    #[ORM\Column(length: 50)]
    private ?string $numeroAvenant = null;

    /**
     * Date de signature de l'avenant
     */
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dateAvenant = null;

    /**
     * Description des modifications apportées par l'avenant
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Chemin du fichier PDF de l'avenant
     */
    #[ORM\Column(length: 500)]
    private ?string $pdfPath = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    // Note : contrat_id (FK ManyToOne) est défini dans chaque entité concrète
    // (ContratAvenantS10, ContratAvenantS40, etc.) car il pointe vers
    // une entité ContratSxx spécifique par agence.

    // ══════════════════════════════════════════════════════════
    // GETTERS & SETTERS
    // ══════════════════════════════════════════════════════════

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumeroAvenant(): ?string
    {
        return $this->numeroAvenant;
    }

    public function setNumeroAvenant(string $numeroAvenant): static
    {
        $this->numeroAvenant = $numeroAvenant;
        return $this;
    }

    public function getDateAvenant(): ?\DateTimeInterface
    {
        return $this->dateAvenant;
    }

    public function setDateAvenant(\DateTimeInterface $dateAvenant): static
    {
        $this->dateAvenant = $dateAvenant;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;
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
}
