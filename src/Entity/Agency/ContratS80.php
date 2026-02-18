<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS80Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS80Repository::class)]
#[ORM\Table(name: 'contrat_s80')]
#[ORM\HasLifecycleCallbacks]
class ContratS80
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS80s')]
    private ?ContactS80 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS80>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS80::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS80
    {
        return $this->contact;
    }

    public function setContact(?ContactS80 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS80>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS80 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS80 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
