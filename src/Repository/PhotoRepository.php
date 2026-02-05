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
     * Trouve les photos d'un équipement par son code
     */
    public function findByEquipmentCode(string $codeEquipement): ?Photo
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.code_equipement = :code')
            ->setParameter('code', $codeEquipement)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve toutes les photos d'un client pour une visite donnée
     */
    public function findByContactAndVisite(string $idContact, string $visite, string $annee): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id_contact = :idContact')
            ->andWhere('p.visite = :visite')
            ->andWhere('p.annee = :annee')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->orderBy('p.code_equipement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les photos par form_id et data_id Kizeo (pour déduplication)
     */
    public function findByKizeoIds(string $formId, string $dataId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.form_id = :formId')
            ->andWhere('p.data_id = :dataId')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si une photo existe déjà pour ce form/data/equipment
     */
    public function existsByKizeoKey(string $formId, string $dataId, string $equipmentId): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.form_id = :formId')
            ->andWhere('p.data_id = :dataId')
            ->andWhere('p.equipment_id = :equipmentId')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->setParameter('equipmentId', $equipmentId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Vérifie si une photo existe déjà pour ce form/data/numero_equipement
     * Utilisé par PhotoPersister pour la déduplication (clé unique uk_photo_equip)
     */
    public function existsByFormDataNumero(string $formId, string $dataId, string $numeroEquipement): bool
    {
        $count = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.form_id = :formId')
            ->andWhere('p.data_id = :dataId')
            ->andWhere('p.numero_equipement = :numero')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->setParameter('numero', $numeroEquipement)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Compte les photos par agence
     */
    public function countByAgence(string $codeAgence): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.code_agence = :code')
            ->setParameter('code', $codeAgence)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Trouve toutes les photos d'un client (tous les visites confondues)
     */
    public function findAllByContact(string $idContact): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id_contact = :idContact')
            ->setParameter('idContact', $idContact)
            ->orderBy('p.annee', 'DESC')
            ->addOrderBy('p.visite', 'DESC')
            ->addOrderBy('p.code_equipement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les photos non encore téléchargées (pour DownloadMediaCommand)
     * 
     * @return Photo[]
     */
    public function findNotDownloaded(int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.is_downloaded = false')
            ->orderBy('p.date_enregistrement', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}