<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS140Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS140Repository::class)]
#[ORM\Table(name: 'contrat_s140')]
#[ORM\HasLifecycleCallbacks]
class ContratS140
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS140s')]
    private ?ContactS140 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS140>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS140::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS140
    {
        return $this->contact;
    }

    public function setContact(?ContactS140 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS140>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS140 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS140 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
