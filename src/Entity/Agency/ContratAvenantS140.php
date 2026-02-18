<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS140Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS140Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s140')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS140
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s140.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS140::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS140 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS140
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS140 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
