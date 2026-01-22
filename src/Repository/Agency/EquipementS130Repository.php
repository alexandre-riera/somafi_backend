<?php

namespace App\Repository\Agency;

use App\Entity\Agency\EquipementS130;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipementS130>
 */
class EquipementS130Repository extends ServiceEntityRepository
{
    use EquipementRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipementS130::class);
    }
}
