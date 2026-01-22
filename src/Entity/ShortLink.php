<?php
// ===== 2. ENTITÉ POUR LES LIENS COURTS =====

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Repository\ShortLinkRepository")]
#[ORM\Table(name: "short_links")]
#[ORM\Index(name: "idx_short_code", columns: ["short_code"])]
#[ORM\Index(name: "idx_expires_at", columns: ["expires_at"])]
class ShortLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "string", length: 10, unique: true)]
    private string $shortCode;

    #[ORM\Column(type: "string", length: 255)]
    private string $originalUrl;

    #[ORM\Column(type: "string", length: 50)]
    private string $agence;

    #[ORM\Column(type: "string", length: 50)]
    private string $clientId;

    #[ORM\Column(type: "string", length: 4)]
    private string $annee;

    #[ORM\Column(type: "string", length: 50)]
    private string $visite;

    #[ORM\Column(type: "datetime")]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: "integer", options: ["default" => 0])]
    private int $clickCount = 0;

    #[ORM\Column(type: "datetime", nullable: true)]
    private ?\DateTimeInterface $lastAccessedAt = null;



    // Getters et Setters
    public function getId(): ?int { return $this->id; }
    
    public function getShortCode(): string { return $this->shortCode; }
    public function setShortCode(string $shortCode): self { $this->shortCode = $shortCode; return $this; }
    
    public function getOriginalUrl(): string { return $this->originalUrl; }
    public function setOriginalUrl(string $originalUrl): self { $this->originalUrl = $originalUrl; return $this; }
    
    public function getAgence(): string { return $this->agence; }
    public function setAgence(string $agence): self { $this->agence = $agence; return $this; }
    
    public function getClientId(): string { return $this->clientId; }
    public function setClientId(string $clientId): self { $this->clientId = $clientId; return $this; }
    
    public function getAnnee(): string { return $this->annee; }
    public function setAnnee(string $annee): self { $this->annee = $annee; return $this; }
    
    public function getVisite(): string { return $this->visite; }
    public function setVisite(string $visite): self { $this->visite = $visite; return $this; }
    
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): self { $this->createdAt = $createdAt; return $this; }
    
    public function getExpiresAt(): ?\DateTimeInterface { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeInterface $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    
    public function getClickCount(): int { return $this->clickCount; }
    public function setClickCount(int $clickCount): self { $this->clickCount = $clickCount; return $this; }
    
    public function getLastAccessedAt(): ?\DateTimeInterface { return $this->lastAccessedAt; }
    public function setLastAccessedAt(?\DateTimeInterface $lastAccessedAt): self { $this->lastAccessedAt = $lastAccessedAt; return $this; }

    public function incrementClickCount(): self
    {
        $this->clickCount++;
        $this->lastAccessedAt = new \DateTime();
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }
}