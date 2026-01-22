<?php

namespace App\Repository\Agency;

use App\Entity\Agency\EquipementS160;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipementS160>
 */
class EquipementS160Repository extends ServiceEntityRepository
{
    use EquipementRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementS160::class);
    }
}
