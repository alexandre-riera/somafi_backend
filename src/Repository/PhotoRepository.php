<?php

namespace App\Repository;

use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /**
     * Trouve les photos d'un contact pour une visite
     * 
     * @return Photo[]
     */
    public function findByContactVisiteAnnee(int $idContact, string $visite, string $annee): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.idContact = :idContact')
            ->andWhere('p.visite = :visite')
            ->andWhere('p.annee = :annee')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve la photo d'un équipement spécifique
     */
    public function findByEquipement(int $idContact, string $numeroEquipement, string $visite, string $annee): ?Photo
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.idContact = :idContact')
            ->andWhere('p.numeroEquipement = :numero')
            ->andWhere('p.visite = :visite')
            ->andWhere('p.annee = :annee')
            ->setParameter('idContact', $idContact)
            ->setParameter('numero', $numeroEquipement)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve les photos non téléchargées (pour CRON)
     * 
     * @return Photo[]
     */
    public function findNotDownloaded(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isDownloaded = false')
            ->andWhere('p.kizeoMediaName IS NOT NULL')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
