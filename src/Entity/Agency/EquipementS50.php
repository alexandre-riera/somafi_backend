<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS50Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS50Repository::class)]
#[ORM\Table(name: 'equipement_s50')]
#[ORM\Index(name: 'idx_s50_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s50_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s50_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s50_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s50_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS50
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
        return 'S50';
    }
}
