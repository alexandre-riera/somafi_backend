<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS100Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS100Repository::class)]
#[ORM\Table(name: 'contrat_s100')]
#[ORM\HasLifecycleCallbacks]
class ContratS100
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS100s')]
    private ?ContactS100 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS100>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS100::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS100
    {
        return $this->contact;
    }

    public function setContact(?ContactS100 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS100>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS100 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS100 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
