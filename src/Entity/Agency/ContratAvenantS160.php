<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS160Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS160Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s160')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS160
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s160.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS160::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS160 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS160
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS160 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
