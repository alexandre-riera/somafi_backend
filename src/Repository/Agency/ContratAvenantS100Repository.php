<?php

namespace App\Repository\Agency;

use App\Entity\Agency\ContratAvenantS100;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratAvenantS100>
 *
 * @method ContratAvenantS100|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContratAvenantS100|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContratAvenantS100[]    findAll()
 * @method ContratAvenantS100[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContratAvenantS100Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratAvenantS100::class);
    }

    /**
     * Trouve tous les avenants d'un contrat, triés par date
     *
     * @return ContratAvenantS100[]
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
    public function findLastByContrat(int $contratId): ?ContratAvenantS100
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
