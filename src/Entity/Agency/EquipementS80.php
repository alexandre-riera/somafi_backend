<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS80Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS80Repository::class)]
#[ORM\Table(name: 'equipement_s80')]
#[ORM\Index(name: 'idx_s80_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s80_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s80_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s80_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s80_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS80
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
        return 'S80';
    }
}
