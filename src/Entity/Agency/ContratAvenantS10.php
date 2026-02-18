<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS10Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS10Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s10')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS10
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s10.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS10::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS10 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS10
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS10 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
