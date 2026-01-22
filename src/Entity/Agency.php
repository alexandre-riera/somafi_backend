<?php

namespace App\Entity;

use App\Repository\AgencyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgencyRepository::class)]
#[ORM\Table(name: 'agencies')]
class Agency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Code agence : S10, S40, S50, etc.
     */
    #[ORM\Column(length: 10, unique: true)]
    private ?string $code = null;

    /**
     * Nom complet de l'agence
     */
    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    /**
     * ID du formulaire Kizeo Forms pour cette agence
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoFormId = null;

    /**
     * ID de la liste interne Kizeo (équipements)
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoListId = null;

    /**
     * ID de la liste externe Kizeo
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoExternalListId = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getKizeoFormId(): ?int
    {
        return $this->kizeoFormId;
    }

    public function setKizeoFormId(?int $kizeoFormId): static
    {
        $this->kizeoFormId = $kizeoFormId;
        return $this;
    }

    public function getKizeoListId(): ?int
    {
        return $this->kizeoListId;
    }

    public function setKizeoListId(?int $kizeoListId): static
    {
        $this->kizeoListId = $kizeoListId;
        return $this;
    }

    public function getKizeoExternalListId(): ?int
    {
        return $this->kizeoExternalListId;
    }

    public function setKizeoExternalListId(?int $kizeoExternalListId): static
    {
        $this->kizeoExternalListId = $kizeoExternalListId;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Retourne l'adresse complète formatée
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->adresse,
            trim(($this->codePostal ?? '') . ' ' . ($this->ville ?? '')),
        ]);
        
        return implode(', ', $parts);
    }

    /**
     * Retourne le nom de la table équipement pour cette agence
     */
    public function getEquipmentTableName(): string
    {
        return 'equipement_' . strtolower($this->code);
    }

    /**
     * Retourne le nom de la classe entité équipement pour cette agence
     */
    public function getEquipmentEntityClass(): string
    {
        return 'App\\Entity\\Agency\\Equipement' . $this->code;
    }

    public function __toString(): string
    {
        return $this->code . ' - ' . $this->nom;
    }
}
