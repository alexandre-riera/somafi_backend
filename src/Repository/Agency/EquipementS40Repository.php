<?php

namespace App\Repository\Agency;

use App\Entity\Agency\EquipementS40;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipementS40>
 */
class EquipementS40Repository extends ServiceEntityRepository
{
    use EquipementRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementS40::class);
    }
}
