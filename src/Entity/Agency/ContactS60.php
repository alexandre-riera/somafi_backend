<?php

namespace App\Entity\Agency;

use App\Repository\ContactS60Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContactS60Repository::class)]
#[ORM\Table(name: 'contact_s60')]
class ContactS60
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $prenom = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adressep_1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adressep_2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cpostalp = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $villep = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rib = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contact_site = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $id_contact = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $raison_sociale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $id_societe = null;

    /**
     * @var Collection<int, ContratS60>
     */
    #[ORM\OneToMany(targetEntity: ContratS60::class, mappedBy: 'contact')]
    private Collection $contratS60s;

    /**
     * @var Collection<int, MailS60>
     */
    #[ORM\OneToMany(targetEntity: MailS60::class, mappedBy: 'id_contact')]
    private Collection $mailS60s;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    public function __construct()
    {
        $this->contratS60s = new ArrayCollection();
        $this->mailS60s = new ArrayCollection();
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getAdressep1(): ?string
    {
        return $this->adressep_1;
    }

    public function setAdressep1(string $adressep_1): static
    {
        $this->adressep_1 = $adressep_1;

        return $this;
    }

    public function getAdressep2(): ?string
    {
        return $this->adressep_2;
    }

    public function setAdressep2(?string $adressep_2): static
    {
        $this->adressep_2 = $adressep_2;

        return $this;
    }

    public function getCpostalp(): ?string
    {
        return $this->cpostalp;
    }

    public function setCpostalp(string $cpostalp): static
    {
        $this->cpostalp = $cpostalp;

        return $this;
    }

    public function getVillep(): ?string
    {
        return $this->villep;
    }

    public function setVillep(string $villep): static
    {
        $this->villep = $villep;

        return $this;
    }

    public function getRib(): ?string
    {
        return $this->rib;
    }

    public function setRib(?string $rib): static
    {
        $this->rib = $rib;

        return $this;
    }

    public function getContactSite(): ?string
    {
        return $this->contact_site;
    }

    public function setContactSite(string $contact_site): static
    {
        $this->contact_site = $contact_site;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getIdContact(): ?string
    {
        return $this->id_contact;
    }

    public function setIdContact(?string $id_contact): static
    {
        $this->id_contact = $id_contact;

        return $this;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raison_sociale;
    }

    public function setRaisonSociale(?string $raison_sociale): static
    {
        $this->raison_sociale = $raison_sociale;

        return $this;
    }

    public function getIdSociete(): ?string
    {
        return $this->id_societe;
    }

    public function setIdSociete(?string $id_societe): static
    {
        $this->id_societe = $id_societe;

        return $this;
    }

    /**
     * @return Collection<int, ContratS60>
     */
    public function getContratS60s(): Collection
    {
        return $this->contratS60s;
    }

    public function addContratS60(ContratS60 $contratS60): static
    {
        if (!$this->contratS60s->contains($contratS60)) {
            $this->contratS60s->add($contratS60);
            $contratS60->setContact($this);
        }

        return $this;
    }

    public function removeContratS60(ContratS60 $contratS60): static
    {
        if ($this->contratS60s->removeElement($contratS60)) {
            // set the owning side to null (unless already changed)
            if ($contratS60->getContact() === $this) {
                $contratS60->setContact(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, MailS60>
     */
    public function getMailS60s(): Collection
    {
        return $this->mailS60s;
    }

    public function addMailS60(MailS60 $mailS60): static
    {
        if (!$this->mailS60s->contains($mailS60)) {
            $this->mailS60s->add($mailS60);
            $mailS60->setIdContact($this);
        }

        return $this;
    }

    public function removeMailS60(MailS60 $mailS60): static
    {
        if ($this->mailS60s->removeElement($mailS60)) {
            // set the owning side to null (unless already changed)
            if ($mailS60->getIdContact() === $this) {
                $mailS60->setIdContact(null);
            }
        }

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
}
