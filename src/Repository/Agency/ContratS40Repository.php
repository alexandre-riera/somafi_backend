<?php

namespace App\Repository\Agency;

use App\Entity\Agency\ContratS40;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratS40>
 *
 * @method ContratS40|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContratS40|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContratS40[]    findAll()
 * @method ContratS40[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContratS40Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratS40::class);
    }

    /**
     * Trouve les contrats actifs d'un client
     *
     * @return ContratS40[]
     */
    public function findContratsActifsByContact(int $contactId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.contact = :contactId')
            ->andWhere('c.statut = :statut')
            ->setParameter('contactId', $contactId)
            ->setParameter('statut', 'actif')
            ->orderBy('c.dateDebutContrat', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve tous les contrats d'un client (tous statuts)
     *
     * @return ContratS40[]
     */
    public function findAllByContact(int $contactId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.contact = :contactId')
            ->setParameter('contactId', $contactId)
            ->orderBy('c.dateDebutContrat', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les contrats par statut
     *
     * @return array<string, int>
     */
    public function countByStatut(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.statut, COUNT(c.id) as total')
            ->groupBy('c.statut')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['statut']] = (int) $row['total'];
        }

        return $counts;
    }
}
