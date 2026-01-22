<?php

namespace App\Repository;

use App\Entity\Agency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agency>
 */
class AgencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agency::class);
    }

    /**
     * Trouve une agence par son code
     */
    public function findByCode(string $code): ?Agency
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /**
     * Trouve toutes les agences actives
     * 
     * @return Agency[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.isActive = true')
            ->orderBy('a.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les agences avec un form Kizeo configuré
     * 
     * @return Agency[]
     */
    public function findWithKizeoForm(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.isActive = true')
            ->andWhere('a.kizeoFormId IS NOT NULL')
            ->orderBy('a.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
