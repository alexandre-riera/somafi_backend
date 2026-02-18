<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS100Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS100Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s100')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS100
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s100.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS100::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS100 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS100
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS100 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
