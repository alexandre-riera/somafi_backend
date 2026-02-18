<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS40Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS40Repository::class)]
#[ORM\Table(name: 'contrat_s40')]
#[ORM\HasLifecycleCallbacks]
class ContratS40
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS40s')]
    private ?ContactS40 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS40>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS40::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS40
    {
        return $this->contact;
    }

    public function setContact(?ContactS40 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS40>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS40 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS40 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
