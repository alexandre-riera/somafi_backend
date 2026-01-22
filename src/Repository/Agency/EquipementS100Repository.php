<?php

namespace App\Repository\Agency;

use App\Entity\Agency\EquipementS100;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipementS100>
 */
class EquipementS100Repository extends ServiceEntityRepository
{
    use EquipementRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementS100::class);
    }
}
