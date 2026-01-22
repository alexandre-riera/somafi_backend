<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS150Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EquipementS150Repository::class)]
#[ORM\Table(name: 'equipement_s150')]
#[ORM\Index(name: 'idx_s150_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s150_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s150_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s150_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s150_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS150
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
        return 'S150';
    }
}
