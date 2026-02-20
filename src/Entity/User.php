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
    // ----------------------------------------------------------------
    // Rôles globaux
    // ----------------------------------------------------------------
    public const ROLE_USER                       = 'ROLE_USER';
    public const ROLE_ADMIN                      = 'ROLE_ADMIN';
    public const ROLE_ADMIN_AGENCE               = 'ROLE_ADMIN_AGENCE';
    public const ROLE_USER_AGENCE                = 'ROLE_USER_AGENCE';
    public const ROLE_EDIT                       = 'ROLE_EDIT';
    public const ROLE_DELETE                     = 'ROLE_DELETE';
    public const ROLE_GESTIONNAIRE_CONTRAT_ENTRETIEN = 'ROLE_GESTIONNAIRE_CONTRAT_ENTRETIEN';

    // ----------------------------------------------------------------
    // Rôles spécifiques CC (gardés dans roles[] pour la sécurité Symfony,
    // mais désormais DÉRIVÉS depuis userContratCadres au moment de la sauvegarde)
    // Supprimés : ROLE_CLIENT_CC, ROLE_ADMIN_CC
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Colonnes
    // ----------------------------------------------------------------
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    private ?string $prenom = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Agences accessibles (JSON array de codes : S10, S40, etc.)
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $agencies = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    // ----------------------------------------------------------------
    // Relation CC (remplace l'ancienne FK contrat_cadre_id)
    // ----------------------------------------------------------------
    /** @var Collection<int, UserContratCadre> */
    #[ORM\OneToMany(targetEntity: UserContratCadre::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $userContratCadres;

    public function __construct()
    {
        $this->createdAt       = new \DateTime();
        $this->roles           = [self::ROLE_USER];
        $this->userContratCadres = new ArrayCollection();
    }

    // ----------------------------------------------------------------
    // Getters / Setters de base
    // ----------------------------------------------------------------
    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return (string) $this->email; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles   = $this->roles;
        $roles[] = self::ROLE_USER;
        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function addRole(string $role): static
    {
        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
        return $this;
    }

    public function removeRole(string $role): static
    {
        $this->roles = array_values(array_filter($this->roles, fn($r) => $r !== $role));
        return $this;
    }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}

    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }

    public function getFullName(): string { return trim($this->prenom . ' ' . $this->nom); }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    /** @return array<string>|null */
    public function getAgencies(): ?array { return $this->agencies; }

    /** @param array<string>|null $agencies */
    public function setAgencies(?array $agencies): static { $this->agencies = $agencies; return $this; }

    public function addAgency(string $agencyCode): static
    {
        if (!in_array($agencyCode, $this->agencies ?? [], true)) {
            $this->agencies[] = $agencyCode;
        }
        return $this;
    }

    public function removeAgency(string $agencyCode): static
    {
        $this->agencies = array_values(array_filter($this->agencies ?? [], fn($a) => $a !== $agencyCode));
        return $this;
    }

    public function hasAccessToAgency(string $agencyCode): bool
    {
        if (in_array(self::ROLE_ADMIN, $this->roles, true)) {
            return true;
        }
        return in_array($agencyCode, $this->agencies ?? [], true);
    }

    public function isAdmin(): bool          { return in_array(self::ROLE_ADMIN, $this->roles, true); }
    public function isAdminAgence(): bool     { return in_array(self::ROLE_ADMIN_AGENCE, $this->roles, true); }
    public function isMultiAgency(): bool     { return count($this->agencies ?? []) > 1; }

    public function isGestionnaireContrat(): bool
    {
        return in_array(self::ROLE_GESTIONNAIRE_CONTRAT_ENTRETIEN, $this->getRoles(), true)
            || in_array(self::ROLE_ADMIN_AGENCE, $this->getRoles(), true)
            || in_array(self::ROLE_ADMIN, $this->getRoles(), true);
    }

    // ----------------------------------------------------------------
    // Gestion des associations CC
    // ----------------------------------------------------------------

    /** @return Collection<int, UserContratCadre> */
    public function getUserContratCadres(): Collection
    {
        return $this->userContratCadres;
    }

    /**
     * Retourne les CCs dont l'utilisateur est admin SOMAFI
     * @return Collection<int, UserContratCadre>
     */
    public function getContratCadresAdmin(): Collection
    {
        return $this->userContratCadres->filter(
            fn(UserContratCadre $ucc) => $ucc->isAdmin()
        );
    }

    /**
     * Retourne les CCs dont l'utilisateur est client (lecture)
     * @return Collection<int, UserContratCadre>
     */
    public function getContratCadresUser(): Collection
    {
        return $this->userContratCadres->filter(
            fn(UserContratCadre $ucc) => $ucc->isUser()
        );
    }

    /**
     * Vérifie si l'utilisateur a accès (admin ou user) à un CC donné
     */
    public function hasAccessToContratCadre(int $contratCadreId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        foreach ($this->userContratCadres as $ucc) {
            if ($ucc->getContratCadre()->getId() === $contratCadreId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifie si l'utilisateur est admin d'un CC donné
     */
    public function isAdminOfContratCadre(int $contratCadreId): bool
    {
        foreach ($this->userContratCadres as $ucc) {
            if ($ucc->getContratCadre()->getId() === $contratCadreId && $ucc->isAdmin()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ajoute une association CC.
     * Évite les doublons (même CC + même role_type).
     */
    public function addContratCadre(ContratCadre $cc, string $roleType = UserContratCadre::ROLE_TYPE_USER): static
    {
        foreach ($this->userContratCadres as $ucc) {
            if ($ucc->getContratCadre() === $cc && $ucc->getRoleType() === $roleType) {
                return $this; // déjà présent
            }
        }
        $ucc = new UserContratCadre($this, $cc, $roleType);
        $this->userContratCadres->add($ucc);
        return $this;
    }

    /**
     * Supprime une association CC par role_type.
     */
    public function removeContratCadre(ContratCadre $cc, string $roleType): static
    {
        foreach ($this->userContratCadres as $ucc) {
            if ($ucc->getContratCadre() === $cc && $ucc->getRoleType() === $roleType) {
                $this->userContratCadres->removeElement($ucc);
                break;
            }
        }
        return $this;
    }

    /**
     * Remplace toutes les associations CC en une seule opération.
     * Utilisé par le controller au moment de la sauvegarde du formulaire.
     *
     * @param array<int, string> $adminCcIds   IDs des CCs où l'user est admin
     * @param array<int, string> $userCcIds    IDs des CCs où l'user est client
     * @param ContratCadre[]     $allCc        Tous les ContratCadre disponibles (indexés par id)
     */
    public function syncContratCadres(array $adminCcIds, array $userCcIds, array $allCcById): static
    {
        // Vider les associations existantes
        $this->userContratCadres->clear();

        foreach ($adminCcIds as $id) {
            if (isset($allCcById[$id])) {
                $ucc = new UserContratCadre($this, $allCcById[$id], UserContratCadre::ROLE_TYPE_ADMIN);
                $this->userContratCadres->add($ucc);
            }
        }

        foreach ($userCcIds as $id) {
            if (isset($allCcById[$id])) {
                // On évite qu'un CC soit à la fois admin et user pour le même user
                if (!in_array($id, $adminCcIds, true)) {
                    $ucc = new UserContratCadre($this, $allCcById[$id], UserContratCadre::ROLE_TYPE_USER);
                    $this->userContratCadres->add($ucc);
                }
            }
        }

        return $this;
    }

    // ----------------------------------------------------------------
    // Lifecycle / utils
    // ----------------------------------------------------------------
    #[ORM\PreUpdate]
    public function preUpdate(): void { $this->updatedAt = new \DateTime(); }

    public function getCreatedAt(): ?\DateTimeInterface  { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $d): static { $this->createdAt = $d; return $this; }
    public function getUpdatedAt(): ?\DateTimeInterface  { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeInterface $d): static { $this->updatedAt = $d; return $this; }
    public function getLastLoginAt(): ?\DateTimeInterface { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeInterface $d): static { $this->lastLoginAt = $d; return $this; }

    public function __toString(): string
    {
        return $this->getFullName() ?: $this->email ?? '';
    }
}
