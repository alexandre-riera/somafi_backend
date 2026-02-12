<?php

namespace App\Entity;

use App\Repository\ContactsCCRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactsCCRepository::class)]
class ContactsCC
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $id_contact = null;

    #[ORM\Column(length: 255)]
    private ?string $raison_sociale_contact = null;

    #[ORM\Column(length: 255)]
    private ?string $code_agence = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'contrat_cadre_id', nullable: true)]
    private ?ContratCadre $contratCadre = null;

    /**
     * @var Collection<int, FilesCC>
     */
    #[ORM\OneToMany(targetEntity: FilesCC::class, mappedBy: 'id_contact_cc')]
    private Collection $filesCCs;

    public function __construct()
    {
        $this->filesCCs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdContact(): ?string
    {
        return $this->id_contact;
    }

    public function setIdContact(string $id_contact): static
    {
        $this->id_contact = $id_contact;
        return $this;
    }

    public function getRaisonSocialeContact(): ?string
    {
        return $this->raison_sociale_contact;
    }

    public function setRaisonSocialeContact(string $raison_sociale_contact): static
    {
        $this->raison_sociale_contact = $raison_sociale_contact;
        return $this;
    }

    public function getCodeAgence(): ?string
    {
        return $this->code_agence;
    }

    public function setCodeAgence(string $code_agence): static
    {
        $this->code_agence = $code_agence;
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

    /**
     * @return Collection<int, FilesCC>
     */
    public function getFilesCCs(): Collection
    {
        return $this->filesCCs;
    }

    public function addFilesCC(FilesCC $filesCC): static
    {
        if (!$this->filesCCs->contains($filesCC)) {
            $this->filesCCs->add($filesCC);
            $filesCC->setIdContactCc($this);
        }
        return $this;
    }

    public function removeFilesCC(FilesCC $filesCC): static
    {
        if ($this->filesCCs->removeElement($filesCC)) {
            if ($filesCC->getIdContactCc() === $this) {
                $filesCC->setIdContactCc(null);
            }
        }
        return $this;
    }
}