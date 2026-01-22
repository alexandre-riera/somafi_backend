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
}
