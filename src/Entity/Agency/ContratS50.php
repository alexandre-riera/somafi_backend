<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS50Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS50Repository::class)]
#[ORM\Table(name: 'contrat_s50')]
#[ORM\HasLifecycleCallbacks]
class ContratS50
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS50s')]
    private ?ContactS50 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS50>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS50::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS50
    {
        return $this->contact;
    }

    public function setContact(?ContactS50 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS50>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS50 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS50 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
