<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS170Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS170Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s170')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS170
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s170.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS170::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS170 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS170
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS170 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
