<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS60Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS60Repository::class)]
#[ORM\Table(name: 'equipement_s60')]
#[ORM\Index(name: 'idx_s60_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s60_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s60_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s60_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s60_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS60
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
        return 'S60';
    }
}
