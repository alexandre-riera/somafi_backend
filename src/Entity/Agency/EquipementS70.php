<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS70Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS70Repository::class)]
#[ORM\Table(name: 'equipement_s70')]
#[ORM\Index(name: 'idx_s70_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s70_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s70_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s70_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s70_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS70
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
        return 'S70';
    }
}
