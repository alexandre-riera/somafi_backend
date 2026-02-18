<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS10Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS10Repository::class)]
#[ORM\Table(name: 'contrat_s10')]
#[ORM\HasLifecycleCallbacks]
class ContratS10
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS10s')]
    private ?ContactS10 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS10>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS10::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS10
    {
        return $this->contact;
    }

    public function setContact(?ContactS10 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS10>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS10 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS10 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
