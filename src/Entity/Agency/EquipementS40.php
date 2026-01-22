<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS40Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS40Repository::class)]
#[ORM\Table(name: 'equipement_s40')]
#[ORM\Index(name: 'idx_s40_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s40_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s40_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s40_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s40_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS40
{
    use EquipementTrait;

    public function __construct()
    {
        $this->dateEnregistrement = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->dateModification = new \DateTime();
    }

    public function getAgencyCode(): string
    {
        return 'S40';
    }
}
