<?php

namespace App\Entity\Agency;

use App\Repository\ContratRepositoryS10;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratRepositoryS10::class)]
class ContratS10
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $numero_contrat = null;

    #[ORM\Column(length: 255)]
    private ?string $date_signature = null;

    #[ORM\Column(length: 255)]
    private ?string $duree = null;

    #[ORM\Column]
    private ?bool $tacite_reconduction = null;

    #[ORM\Column(length: 255)]
    private ?string $valorisation = null;

    #[ORM\Column]
    private ?int $nombre_equipement = null;

    #[ORM\Column]
    private ?int $nombre_visite = null;

    #[ORM\Column(length: 255)]
    private ?string $date_resiliation = null;

    #[ORM\Column(length: 255)]
    private ?string $statut = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $date_previsionnelle_1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $date_previsionnelle_2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $date_effective_1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $date_effective_2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $id_contact = null;

    /**
     * @var Collection<int, EquipementS10>
     */
    #[ORM\OneToMany(targetEntity: EquipementS10::class, mappedBy: 'contratS10')]
    private Collection $equipements;

    #[ORM\ManyToOne(inversedBy: 'contratS10s')]
    private ?ContactS10 $contact = null;

    public function __construct()
    {
        $this->equipements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumeroContrat(): ?int
    {
        return $this->numero_contrat;
    }

    public function setNumeroContrat(int $numero_contrat): static
    {
        $this->numero_contrat = $numero_contrat;

        return $this;
    }

    public function getDateSignature(): ?string
    {
        return $this->date_signature;
    }

    public function setDateSignature(string $date_signature): static
    {
        $this->date_signature = $date_signature;

        return $this;
    }

    public function getDuree(): ?string
    {
        return $this->duree;
    }

    public function setDuree(string $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function isTaciteReconduction(): ?bool
    {
        return $this->tacite_reconduction;
    }

    public function setTaciteReconduction(bool $tacite_reconduction): static
    {
        $this->tacite_reconduction = $tacite_reconduction;

        return $this;
    }

    public function getValorisation(): ?string
    {
        return $this->valorisation;
    }

    public function setValorisation(string $valorisation): static
    {
        $this->valorisation = $valorisation;

        return $this;
    }

    public function getNombreEquipement(): ?int
    {
        return $this->nombre_equipement;
    }

    public function setNombreEquipement(int $nombre_equipement): static
    {
        $this->nombre_equipement = $nombre_equipement;

        return $this;
    }

    public function getNombreVisite(): ?int
    {
        return $this->nombre_visite;
    }

    public function setNombreVisite(int $nombre_visite): static
    {
        $this->nombre_visite = $nombre_visite;

        return $this;
    }

    public function getDateResiliation(): ?string
    {
        return $this->date_resiliation;
    }

    public function setDateResiliation(?string $date_resiliation): static
    {
        $this->date_resiliation = $date_resiliation;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDatePrevisionnelle1(): ?string
    {
        return $this->date_previsionnelle_1;
    }

    public function setDatePrevisionnelle1(?string $date_previsionnelle_1): static
    {
        $this->date_previsionnelle_1 = $date_previsionnelle_1;

        return $this;
    }

    public function getDatePrevisionnelle2(): ?string
    {
        return $this->date_previsionnelle_2;
    }

    public function setDatePrevisionnelle2(?string $date_previsionnelle_2): static
    {
        $this->date_previsionnelle_2 = $date_previsionnelle_2;

        return $this;
    }

    public function getDateEffective1(): ?string
    {
        return $this->date_effective_1;
    }

    public function setDateEffective1(?string $date_effective_1): static
    {
        $this->date_effective_1 = $date_effective_1;

        return $this;
    }

    public function getDateEffective2(): ?string
    {
        return $this->date_effective_2;
    }

    public function setDateEffective2(?string $date_effective_2): static
    {
        $this->date_effective_2 = $date_effective_2;

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

    /**
     * @return Collection<int, EquipementS10>
     */
    public function getEquipements(): Collection
    {
        return $this->equipements;
    }

    public function addEquipement(EquipementS10 $equipement): static
    {
        if (!$this->equipements->contains($equipement)) {
            $this->equipements->add($equipement);
            $equipement->setContratS10($this);
        }

        return $this;
    }

    public function removeEquipement(EquipementS10 $equipement): static
    {
        if ($this->equipements->removeElement($equipement)) {
            // set the owning side to null (unless already changed)
            if ($equipement->getContratS10() === $this) {
                $equipement->setContratS10(null);
            }
        }

        return $this;
    }

    public function getContact(): ?ContactS10
    {
        return $this->contact;
    }

    public function setContact(?ContactS10 $contact): static
    {
        $this->contact = $contact;

        return $this;
    }
}
