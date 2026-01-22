<?php

namespace App\Entity\Agency;

use App\Repository\Agency\EquipementS100Repository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Équipements de l'agence S100 - Montpellier
 */
#[ORM\Entity(repositoryClass: EquipementS100Repository::class)]
#[ORM\Table(name: 'equipement_s100')]
#[ORM\Index(name: 'idx_s100_contact', columns: ['id_contact'])]
#[ORM\Index(name: 'idx_s100_visite', columns: ['visite'])]
#[ORM\Index(name: 'idx_s100_annee', columns: ['annee'])]
#[ORM\Index(name: 'idx_s100_lookup', columns: ['id_contact', 'visite', 'annee'])]
#[ORM\Index(name: 'idx_s100_kizeo_dedup', columns: ['kizeo_form_id', 'kizeo_data_id', 'kizeo_index'])]
#[ORM\HasLifecycleCallbacks]
class EquipementS100
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

    /**
     * Retourne le code agence
     */
    public function getAgencyCode(): string
    {
        return 'S100';
    }
}
