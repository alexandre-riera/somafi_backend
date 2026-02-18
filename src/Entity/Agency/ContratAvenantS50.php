<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS50Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS50Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s50')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS50
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s50.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS50::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS50 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS50
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS50 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
