<?php

namespace App\Repository\Agency;

use App\Entity\Agency\ContratAvenantS10;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratAvenantS10>
 *
 * @method ContratAvenantS10|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContratAvenantS10|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContratAvenantS10[]    findAll()
 * @method ContratAvenantS10[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContratAvenantS10Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratAvenantS10::class);
    }

    /**
     * Trouve tous les avenants d'un contrat, triés par date
     *
     * @return ContratAvenantS10[]
     */
    public function findByContrat(int $contratId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.contrat = :contratId')
            ->setParameter('contratId', $contratId)
            ->orderBy('a.dateAvenant', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne le dernier avenant d'un contrat
     */
    public function findLastByContrat(int $contratId): ?ContratAvenantS10
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.contrat = :contratId')
            ->setParameter('contratId', $contratId)
            ->orderBy('a.dateAvenant', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
