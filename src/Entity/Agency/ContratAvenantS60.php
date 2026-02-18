<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS60Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS60Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s60')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS60
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s60.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS60::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS60 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS60
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS60 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
