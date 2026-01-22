<?php

namespace App\Entity;

use App\Repository\ContratCadreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contrat Cadre - Gère les clients multi-agences comme Kuehne, XPO, etc.
 * 
 * Un contrat cadre permet à un client d'avoir accès à tous ses sites
 * répartis sur les 13 agences de France via une seule interface.
 */
#[ORM\Entity(repositoryClass: ContratCadreRepository::class)]
#[ORM\Table(name: 'contrats_cadre')]
class ContratCadre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nom du contrat cadre (ex: "KUEHNE + NAGEL")
     */
    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    /**
     * Slug pour l'URL (ex: "kuehne" -> /kuehne)
     */
    #[ORM\Column(length: 50, unique: true)]
    private ?string $slug = null;

    /**
     * Pattern de recherche SQL pour filtrer les contacts
     * (ex: "%kuehne%" pour LIKE '%kuehne%')
     */
    #[ORM\Column(length: 100)]
    private ?string $searchPattern = null;

    /**
     * Logo du client (chemin relatif)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    /**
     * Couleur principale (hex)
     */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $primaryColor = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = strtolower($slug);
        return $this;
    }

    public function getSearchPattern(): ?string
    {
        return $this->searchPattern;
    }

    public function setSearchPattern(string $searchPattern): static
    {
        $this->searchPattern = $searchPattern;
        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;
        return $this;
    }

    public function getPrimaryColor(): ?string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(?string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Retourne le rôle admin pour ce contrat cadre
     * Ex: KUEHNE_ADMIN, XPO_ADMIN
     */
    public function getAdminRole(): string
    {
        return strtoupper($this->slug) . '_ADMIN';
    }

    /**
     * Retourne le rôle user pour ce contrat cadre
     * Ex: KUEHNE_USER, XPO_USER
     */
    public function getUserRole(): string
    {
        return strtoupper($this->slug) . '_USER';
    }

    /**
     * Retourne l'URL du front pour ce contrat cadre
     */
    public function getUrl(): string
    {
        return '/' . $this->slug;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}
