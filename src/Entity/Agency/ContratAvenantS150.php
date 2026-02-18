<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS150Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS150Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s150')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS150
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s150.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS150::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS150 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS150
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS150 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
