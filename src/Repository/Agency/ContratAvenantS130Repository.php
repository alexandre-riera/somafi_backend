<?php

namespace App\Repository\Agency;

use App\Entity\Agency\ContratAvenantS130;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratAvenantS130>
 *
 * @method ContratAvenantS130|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContratAvenantS130|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContratAvenantS130[]    findAll()
 * @method ContratAvenantS130[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContratAvenantS130Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratAvenantS130::class);
    }

    /**
     * Trouve tous les avenants d'un contrat, triés par date
     *
     * @return ContratAvenantS130[]
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
    public function findLastByContrat(int $contratId): ?ContratAvenantS130
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
