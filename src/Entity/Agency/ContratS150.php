<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS150Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS150Repository::class)]
#[ORM\Table(name: 'contrat_s150')]
#[ORM\HasLifecycleCallbacks]
class ContratS150
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS150s')]
    private ?ContactS150 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS150>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS150::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS150
    {
        return $this->contact;
    }

    public function setContact(?ContactS150 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS150>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS150 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS150 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
