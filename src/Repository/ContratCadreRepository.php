<?php

namespace App\Repository;

use App\Entity\ContratCadre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContratCadre>
 */
class ContratCadreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContratCadre::class);
    }

    public function findBySlug(string $slug): ?ContratCadre
    {
        return $this->findOneBy(['slug' => strtolower($slug), 'isActive' => true]);
    }

    /**
     * @return ContratCadre[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['nom' => 'ASC']);
    }
}
