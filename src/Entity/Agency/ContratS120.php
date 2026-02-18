<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratS120Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratS120Repository::class)]
#[ORM\Table(name: 'contrat_s120')]
#[ORM\HasLifecycleCallbacks]
class ContratS120
{
    use ContratEntretienTrait;

    #[ORM\ManyToOne(inversedBy: 'contratS120s')]
    private ?ContactS120 $contact = null;

    /**
     * @var Collection<int, ContratAvenantS120>
     */
    #[ORM\OneToMany(targetEntity: ContratAvenantS120::class, mappedBy: 'contrat')]
    private Collection $avenants;

    public function __construct()
    {
        $this->avenants = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    // ── Relations ──

    public function getContact(): ?ContactS120
    {
        return $this->contact;
    }

    public function setContact(?ContactS120 $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    /**
     * @return Collection<int, ContratAvenantS120>
     */
    public function getAvenants(): Collection
    {
        return $this->avenants;
    }

    public function addAvenant(ContratAvenantS120 $avenant): static
    {
        if (!$this->avenants->contains($avenant)) {
            $this->avenants->add($avenant);
            $avenant->setContrat($this);
        }

        return $this;
    }

    public function removeAvenant(ContratAvenantS120 $avenant): static
    {
        if ($this->avenants->removeElement($avenant)) {
            if ($avenant->getContrat() === $this) {
                $avenant->setContrat(null);
            }
        }

        return $this;
    }
}
