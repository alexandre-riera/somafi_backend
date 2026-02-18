<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS170Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS170Repository::class)]
#[ORM\Table(name: 'contrat_s170')]
#[ORM\HasLifecycleCallbacks]
class ContratS170
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS170s')]
    private ?ContactS170 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS170>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS170::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS170
    {
        return $this->contact;
    }

    public function setContact(?ContactS170 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS170>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS170 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS170 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
