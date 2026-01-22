<?php

namespace App\Repository\Agency;

use App\Entity\Agency\EquipementS120;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipementS120>
 */
class EquipementS120Repository extends ServiceEntityRepository
{
    use EquipementRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementS120::class);
    }
}
