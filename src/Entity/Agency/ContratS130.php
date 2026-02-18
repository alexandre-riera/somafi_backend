<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS130Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS130Repository::class)]
#[ORM\Table(name: 'contrat_s130')]
#[ORM\HasLifecycleCallbacks]
class ContratS130
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS130s')]
    private ?ContactS130 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS130>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS130::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS130
    {
        return $this->contact;
    }

    public function setContact(?ContactS130 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS130>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS130 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS130 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
