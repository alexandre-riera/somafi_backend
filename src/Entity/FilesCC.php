<?php

namespace App\Entity;

use App\Repository\FilesCCRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilesCCRepository::class)]
class FilesCC
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\ManyToOne(inversedBy: 'filesCCs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ContactsCC $id_contact_cc = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $original_name = null;

    #[ORM\Column(nullable: true)]
    private ?int $file_size = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true)]
    private ?User $uploadedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $uploaded_at = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'contrat_cadre_id', nullable: true)]
    private ?ContratCadre $contratCadre = null;

    // ===== GETTERS & SETTERS =====

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function getIdContactCc(): ?ContactsCC
    {
        return $this->id_contact_cc;
    }

    public function setIdContactCc(?ContactsCC $id_contact_cc): static
    {
        $this->id_contact_cc = $id_contact_cc;
        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->original_name;
    }

    public function setOriginalName(?string $original_name): static
    {
        $this->original_name = $original_name;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->file_size;
    }

    public function setFileSize(?int $file_size): static
    {
        $this->file_size = $file_size;
        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function getUploadedAt(): ?\DateTimeImmutable
    {
        return $this->uploaded_at;
    }

    public function setUploadedAt(?\DateTimeImmutable $uploaded_at): static
    {
        $this->uploaded_at = $uploaded_at;
        return $this;
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
}