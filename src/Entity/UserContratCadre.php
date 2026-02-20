<?php

namespace App\Entity;

use App\Repository\UserContratCadreRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserContratCadreRepository::class)]
#[ORM\Table(name: 'user_contrat_cadre')]
#[ORM\UniqueConstraint(name: 'uq_user_cc_role', columns: ['user_id', 'contrat_cadre_id', 'role_type'])]
class UserContratCadre
{
    public const ROLE_TYPE_ADMIN = 'admin';
    public const ROLE_TYPE_USER  = 'user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userContratCadres')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: ContratCadre::class, inversedBy: 'userContratCadres')]
    #[ORM\JoinColumn(name: 'contrat_cadre_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ContratCadre $contratCadre;

    #[ORM\Column(type: 'string', columnDefinition: "ENUM('admin','user') NOT NULL DEFAULT 'user'")]
    private string $roleType = self::ROLE_TYPE_USER;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct(User $user, ContratCadre $contratCadre, string $roleType = self::ROLE_TYPE_USER)
    {
        $this->user        = $user;
        $this->contratCadre = $contratCadre;
        $this->roleType    = $roleType;
        $this->createdAt   = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getContratCadre(): ContratCadre { return $this->contratCadre; }
    public function setContratCadre(ContratCadre $contratCadre): static { $this->contratCadre = $contratCadre; return $this; }

    public function getRoleType(): string { return $this->roleType; }
    public function setRoleType(string $roleType): static { $this->roleType = $roleType; return $this; }

    public function isAdmin(): bool { return $this->roleType === self::ROLE_TYPE_ADMIN; }
    public function isUser(): bool  { return $this->roleType === self::ROLE_TYPE_USER; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
