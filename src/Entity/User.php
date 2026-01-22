<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    // Constantes des rôles
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_ADMIN_AGENCE = 'ROLE_ADMIN_AGENCE';
    public const ROLE_USER_AGENCE = 'ROLE_USER_AGENCE';
    public const ROLE_CLIENT_CC = 'ROLE_CLIENT_CC';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    private ?string $prenom = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Agences auxquelles l'utilisateur a accès (ManyToMany simulé via JSON)
     * @var array<string> Liste des codes agences (S10, S40, etc.)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $agencies = [];

    /**
     * Pour les clients Contrat Cadre : ID du contrat cadre associé
     */
    #[ORM\ManyToOne(targetEntity: ContratCadre::class)]
    #[ORM\JoinColumn(name: 'contrat_cadre_id', referencedColumnName: 'id', nullable: true)]
    private ?ContratCadre $contratCadre = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->roles = [self::ROLE_USER];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // Garantir que chaque user a au moins ROLE_USER
        $roles[] = self::ROLE_USER;

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function addRole(string $role): static
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
        return $this;
    }

    public function removeRole(string $role): static
    {
        $this->roles = array_filter($this->roles, fn($r) => $r !== $role);
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // Si vous stockez des données sensibles temporaires, effacez-les ici
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->prenom . ' ' . $this->nom);
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

    /**
     * @return array<string>|null
     */
    public function getAgencies(): ?array
    {
        return $this->agencies;
    }

    /**
     * @param array<string>|null $agencies
     */
    public function setAgencies(?array $agencies): static
    {
        $this->agencies = $agencies;
        return $this;
    }

    public function addAgency(string $agencyCode): static
    {
        if (!in_array($agencyCode, $this->agencies ?? [], true)) {
            $this->agencies[] = $agencyCode;
        }
        return $this;
    }

    public function removeAgency(string $agencyCode): static
    {
        $this->agencies = array_filter($this->agencies ?? [], fn($a) => $a !== $agencyCode);
        return $this;
    }

    /**
     * Vérifie si l'utilisateur a accès à une agence donnée
     */
    public function hasAccessToAgency(string $agencyCode): bool
    {
        // Les admins globaux ont accès à tout
        if (in_array(self::ROLE_ADMIN, $this->roles, true)) {
            return true;
        }

        return in_array($agencyCode, $this->agencies ?? [], true);
    }

    public function getContratCadre(): ?ContratCadre
    {
        return $this->contratCadre;
    }

    public function setContratCadre(?ContratCadre $contratCadre): static
    {
        $this->contratCadre = $contratCadre;
        return $this;
    }

    /**
     * Vérifie si l'utilisateur est un admin de Contrat Cadre
     */
    public function isContratCadreAdmin(): bool
    {
        if ($this->contratCadre === null) {
            return false;
        }

        $adminRole = $this->contratCadre->getAdminRole();
        return in_array($adminRole, $this->roles, true);
    }

    /**
     * Vérifie si l'utilisateur est un client Contrat Cadre
     */
    public function isContratCadreUser(): bool
    {
        return $this->contratCadre !== null && in_array(self::ROLE_CLIENT_CC, $this->roles, true);
    }

    /**
     * Vérifie si l'utilisateur est admin global
     */
    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    /**
     * Vérifie si l'utilisateur est admin d'agence
     */
    public function isAdminAgence(): bool
    {
        return in_array(self::ROLE_ADMIN_AGENCE, $this->roles, true);
    }

    /**
     * Vérifie si l'utilisateur a accès à plusieurs agences
     */
    public function isMultiAgency(): bool
    {
        return count($this->agencies ?? []) > 1;
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

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getLastLoginAt(): ?\DateTimeInterface
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeInterface $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getFullName() ?: $this->email ?? '';
    }
}
