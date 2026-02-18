<?php

namespace App\Repository\Agency;

use App\Entity\Agency\ContratAvenantS160;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratAvenantS160>
 *
 * @method ContratAvenantS160|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContratAvenantS160|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContratAvenantS160[]    findAll()
 * @method ContratAvenantS160[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContratAvenantS160Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratAvenantS160::class);
    }

    /**
     * Trouve tous les avenants d'un contrat, triés par date
     *
     * @return ContratAvenantS160[]
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
    public function findLastByContrat(int $contratId): ?ContratAvenantS160
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
