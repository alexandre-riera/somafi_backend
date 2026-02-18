<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS160Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS160Repository::class)]
#[ORM\Table(name: 'contrat_s160')]
#[ORM\HasLifecycleCallbacks]
class ContratS160
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS160s')]
    private ?ContactS160 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS160>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS160::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS160
    {
        return $this->contact;
    }

    public function setContact(?ContactS160 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS160>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS160 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS160 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
