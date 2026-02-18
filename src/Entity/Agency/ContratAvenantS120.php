<?php

namespace App\Entity\Agency;

use App\Repository\Agency\ContratAvenantS120Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratAvenantS120Repository::class)]
#[ORM\Table(name: 'contrat_avenant_s120')]
#[ORM\HasLifecycleCallbacks]
class ContratAvenantS120
{
    use ContratAvenantTrait;

    /**
     * FK vers contrat_s120.id
     */
    #[ORM\ManyToOne(targetEntity: ContratS120::class, inversedBy: 'avenants')]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: false)]
    private ?ContratS120 $contrat = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getContrat(): ?ContratS120
    {
        return $this->contrat;
    }

    public function setContrat(?ContratS120 $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }
}
