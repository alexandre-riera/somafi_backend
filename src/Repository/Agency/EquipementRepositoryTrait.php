<?php

namespace App\Repository\Agency;

/**
 * Trait contenant les méthodes communes aux 13 repositories Equipement
 */
trait EquipementRepositoryTrait
{
    /**
     * Trouve les équipements d'un contact pour une visite et année donnée
     * 
     * @return array<object>
     */
    public function findByContactVisiteAnnee(int $idContact, string $visite, string $annee): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.visite = :visite')
            ->andWhere('e.annee = :annee')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->orderBy('e.numeroEquipement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les équipements AU CONTRAT d'un contact
     * 
     * @return array<object>
     */
    public function findContractEquipments(int $idContact, string $visite, string $annee): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.visite = :visite')
            ->andWhere('e.annee = :annee')
            ->andWhere('e.isHorsContrat = false')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->orderBy('e.numeroEquipement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les équipements HORS CONTRAT d'un contact
     * 
     * @return array<object>
     */
    public function findOffContractEquipments(int $idContact, string $visite, string $annee): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.visite = :visite')
            ->andWhere('e.annee = :annee')
            ->andWhere('e.isHorsContrat = true')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->orderBy('e.numeroEquipement', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve la dernière visite d'un contact
     * 
     * @return array{annee: string, visite: string, date: \DateTimeInterface}|null
     */
    public function findLastVisit(int $idContact): ?array
    {
        $result = $this->createQueryBuilder('e')
            ->select('e.annee, e.visite, e.dateDerniereVisite')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->orderBy('e.dateDerniereVisite', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result) {
            return [
                'annee' => $result['annee'],
                'visite' => $result['visite'],
                'date' => $result['dateDerniereVisite'],
            ];
        }

        return null;
    }

    /**
     * Trouve les années disponibles pour un contact
     * 
     * @return array<string>
     */
    public function findAvailableYears(int $idContact): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('DISTINCT e.annee')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->orderBy('e.annee', 'DESC')
            ->getQuery()
            ->getResult();

        return array_column($results, 'annee');
    }

    /**
     * Trouve les visites disponibles pour un contact et une année
     * 
     * @return array<string>
     */
    public function findAvailableVisits(int $idContact, string $annee): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('DISTINCT e.visite')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.annee = :annee')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->setParameter('annee', $annee)
            ->orderBy('e.visite', 'ASC')
            ->getQuery()
            ->getResult();

        return array_column($results, 'visite');
    }

    /**
     * Compte les équipements par statut pour un rapport
     * 
     * @return array<string, int>
     */
    public function countByStatus(int $idContact, string $visite, string $annee): array
    {
        $results = $this->createQueryBuilder('e')
            ->select('e.statutEquipement, COUNT(e.id) as total')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.visite = :visite')
            ->andWhere('e.annee = :annee')
            ->andWhere('e.isArchive = false')
            ->setParameter('idContact', $idContact)
            ->setParameter('visite', $visite)
            ->setParameter('annee', $annee)
            ->groupBy('e.statutEquipement')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['statutEquipement'] ?? 'unknown'] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Vérifie si un équipement hors contrat existe déjà (déduplication)
     * Clé: form_id + data_id + index
     */
    public function existsByKizeoKey(int $formId, int $dataId, int $index): bool
    {
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.kizeoFormId = :formId')
            ->andWhere('e.kizeoDataId = :dataId')
            ->andWhere('e.kizeoIndex = :index')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->setParameter('index', $index)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Vérifie si un équipement au contrat existe déjà (déduplication)
     * Clé: id_contact + numero_equipement + visite + date
     */
    public function existsByContractKey(int $idContact, string $numero, string $visite, \DateTimeInterface $date): bool
    {
        $result = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.numeroEquipement = :numero')
            ->andWhere('e.visite = :visite')
            ->andWhere('e.dateDerniereVisite = :date')
            ->setParameter('idContact', $idContact)
            ->setParameter('numero', $numero)
            ->setParameter('visite', $visite)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Trouve le dernier numéro d'équipement pour un type donné (pour génération HC)
     * Ex: pour "Porte sectionnelle" → retourne le dernier SEC (SEC03, SEC04...)
     */
    public function findLastNumberForType(int $idContact, string $prefix): ?string
    {
        $result = $this->createQueryBuilder('e')
            ->select('e.numeroEquipement')
            ->andWhere('e.idContact = :idContact')
            ->andWhere('e.numeroEquipement LIKE :prefix')
            ->setParameter('idContact', $idContact)
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('e.numeroEquipement', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['numeroEquipement'] : null;
    }

    /**
     * Supprime les doublons identifiés (à utiliser avec précaution)
     */
    public function removeDuplicates(int $idContact, string $visite, string $annee): int
    {
        // Cette méthode identifie et supprime les doublons en conservant le plus récent
        // Implémentation à faire selon les règles métier exactes
        return 0;
    }
}
