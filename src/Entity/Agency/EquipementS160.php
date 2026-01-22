<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS160Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS160Repository::class)]
#[ORM\Table(name: 'equipement_s160')]
#[ORM\Index(name: 'idx_s160_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s160_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s160_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s160_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s160_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS160
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
        return 'S160';
    }
}
