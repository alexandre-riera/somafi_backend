<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS60Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS60Repository::class)]
#[ORM\Table(name: 'contrat_s60')]
#[ORM\HasLifecycleCallbacks]
class ContratS60
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS60s')]
    private ?ContactS60 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS60>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS60::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS60
    {
        return $this->contact;
    }

    public function setContact(?ContactS60 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS60>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS60 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS60 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
