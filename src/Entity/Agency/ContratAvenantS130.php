<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS130Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS130Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s130')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS130
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s130.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS130::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS130 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS130
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS130 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
