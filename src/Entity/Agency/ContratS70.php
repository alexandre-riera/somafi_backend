<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS70Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS70Repository::class)]
#[ORM\Table(name: 'contrat_s70')]
#[ORM\HasLifecycleCallbacks]
class ContratS70
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS70s')]
    private ?ContactS70 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS70>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS70::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS70
    {
        return $this->contact;
    }

    public function setContact(?ContactS70 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS70>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS70 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS70 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
