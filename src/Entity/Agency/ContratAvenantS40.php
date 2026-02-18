<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS40Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS40Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s40')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS40
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s40.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS40::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS40 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS40
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS40 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
