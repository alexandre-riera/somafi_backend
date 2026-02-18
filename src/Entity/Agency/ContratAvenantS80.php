<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS80Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS80Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s80')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS80
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s80.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS80::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS80 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS80
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS80 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
