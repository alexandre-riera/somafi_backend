<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS70Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS70Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s70')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS70
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s70.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS70::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS70 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS70
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS70 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
