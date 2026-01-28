<?php

namespace App\Repository;

use App\Entity\KizeoJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les jobs de téléchargement Kizeo
 * 
 * Fournit des méthodes optimisées pour :
 * - Récupérer les jobs à traiter (CRON)
 * - Vérifier l'existence d'un job (évite doublons)
 * - Reset des jobs bloqués
 * - Purge des jobs terminés
 * - Statistiques pour monitoring
 * 
 * @extends ServiceEntityRepository<KizeoJob>
 */
class KizeoJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KizeoJob::class);
    }

    // =========================================================================
    // RÉCUPÉRATION DES JOBS À TRAITER
    // =========================================================================

    /**
     * Récupère les jobs pending par type, triés par priorité puis ancienneté
     * 
     * Utilisé par les CRON pour récupérer le prochain batch à traiter
     * 
     * @return KizeoJob[]
     */
    public function findPendingByType(string $jobType, int $limit = 20): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status = :status')
            ->andWhere('j.jobType = :type')
            ->setParameter('status', KizeoJob::STATUS_PENDING)
            ->setParameter('type', $jobType)
            ->orderBy('j.priority', 'ASC')      // Priorité basse = plus urgent
            ->addOrderBy('j.createdAt', 'ASC')  // Plus ancien d'abord
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les jobs pending pour une agence spécifique
     * 
     * @return KizeoJob[]
     */
    public function findPendingByAgency(string $agencyCode, string $jobType, int $limit = 20): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status = :status')
            ->andWhere('j.jobType = :type')
            ->andWhere('j.agencyCode = :agency')
            ->setParameter('status', KizeoJob::STATUS_PENDING)
            ->setParameter('type', $jobType)
            ->setParameter('agency', strtoupper($agencyCode))
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre de jobs pending par type
     */
    public function countPendingByType(string $jobType): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :status')
            ->andWhere('j.jobType = :type')
            ->setParameter('status', KizeoJob::STATUS_PENDING)
            ->setParameter('type', $jobType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // =========================================================================
    // VÉRIFICATION D'EXISTENCE (ÉVITE DOUBLONS)
    // =========================================================================

    /**
     * Vérifie si un job photo existe déjà
     * 
     * Critères d'unicité: form_id + data_id + media_name
     */
    public function photoJobExists(int $formId, int $dataId, string $mediaName): bool
    {
        $count = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.formId = :formId')
            ->andWhere('j.dataId = :dataId')
            ->andWhere('j.mediaName = :mediaName')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->setParameter('mediaName', $mediaName)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Vérifie si un job PDF existe déjà
     * 
     * Critères d'unicité: form_id + data_id + job_type=pdf
     */
    public function pdfJobExists(int $formId, int $dataId): bool
    {
        $count = $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.formId = :formId')
            ->andWhere('j.dataId = :dataId')
            ->andWhere('j.jobType = :type')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->setParameter('type', KizeoJob::TYPE_PDF)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Trouve un job existant par ses critères d'unicité
     */
    public function findExistingPhotoJob(int $formId, int $dataId, string $mediaName): ?KizeoJob
    {
        return $this->findOneBy([
            'formId' => $formId,
            'dataId' => $dataId,
            'mediaName' => $mediaName,
        ]);
    }

    /**
     * Trouve un job PDF existant
     */
    public function findExistingPdfJob(int $formId, int $dataId): ?KizeoJob
    {
        return $this->findOneBy([
            'formId' => $formId,
            'dataId' => $dataId,
            'jobType' => KizeoJob::TYPE_PDF,
        ]);
    }

    // =========================================================================
    // GESTION DES JOBS BLOQUÉS
    // =========================================================================

    /**
     * Reset les jobs bloqués en "processing" depuis trop longtemps
     * 
     * Un job est considéré bloqué s'il est en "processing" depuis plus de X minutes.
     * Ces jobs sont remis en "pending" pour être retraités au prochain CRON.
     * 
     * @param int $minutes Seuil en minutes (défaut: 60)
     * @return int Nombre de jobs resetés
     */
    public function resetStuckJobs(int $minutes = 60): int
    {
        $threshold = new \DateTime("-{$minutes} minutes");
        
        return $this->createQueryBuilder('j')
            ->update()
            ->set('j.status', ':pending')
            ->where('j.status = :processing')
            ->andWhere('j.startedAt < :threshold')
            ->setParameter('pending', KizeoJob::STATUS_PENDING)
            ->setParameter('processing', KizeoJob::STATUS_PROCESSING)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Trouve les jobs bloqués (pour monitoring/debug)
     * 
     * @return KizeoJob[]
     */
    public function findStuckJobs(int $minutes = 60): array
    {
        $threshold = new \DateTime("-{$minutes} minutes");
        
        return $this->createQueryBuilder('j')
            ->where('j.status = :processing')
            ->andWhere('j.startedAt < :threshold')
            ->setParameter('processing', KizeoJob::STATUS_PROCESSING)
            ->setParameter('threshold', $threshold)
            ->orderBy('j.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // =========================================================================
    // PURGE DES JOBS TERMINÉS
    // =========================================================================

    /**
     * Purge les jobs terminés (done) après X jours
     * 
     * @param int $days Nombre de jours de rétention (défaut: 14)
     * @return int Nombre de jobs supprimés
     */
    public function purgeDoneJobs(int $days = 14): int
    {
        $threshold = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('j')
            ->delete()
            ->where('j.status = :done')
            ->andWhere('j.completedAt < :threshold')
            ->setParameter('done', KizeoJob::STATUS_DONE)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Purge les jobs en échec (failed) après X jours
     * 
     * @param int $days Nombre de jours de rétention (défaut: 30)
     * @return int Nombre de jobs supprimés
     */
    public function purgeFailedJobs(int $days = 30): int
    {
        $threshold = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('j')
            ->delete()
            ->where('j.status = :failed')
            ->andWhere('j.completedAt < :threshold')
            ->setParameter('failed', KizeoJob::STATUS_FAILED)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    // =========================================================================
    // STATISTIQUES ET MONITORING
    // =========================================================================

    /**
     * Statistiques par type de job
     * 
     * @return array{pending: int, processing: int, done: int, failed: int}
     */
    public function getStatsByType(string $jobType): array
    {
        $results = $this->createQueryBuilder('j')
            ->select('j.status, COUNT(j.id) as count')
            ->where('j.jobType = :type')
            ->setParameter('type', $jobType)
            ->groupBy('j.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'failed' => 0,
        ];

        foreach ($results as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Statistiques globales (tous types confondus)
     * 
     * @return array{
     *     photo: array{pending: int, processing: int, done: int, failed: int},
     *     pdf: array{pending: int, processing: int, done: int, failed: int},
     *     total: array{pending: int, processing: int, done: int, failed: int}
     * }
     */
    public function getGlobalStats(): array
    {
        $results = $this->createQueryBuilder('j')
            ->select('j.jobType, j.status, COUNT(j.id) as count')
            ->groupBy('j.jobType, j.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'photo' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0],
            'pdf' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0],
            'total' => ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0],
        ];

        foreach ($results as $row) {
            $type = $row['jobType'];
            $status = $row['status'];
            $count = (int) $row['count'];
            
            $stats[$type][$status] = $count;
            $stats['total'][$status] += $count;
        }

        return $stats;
    }

    /**
     * Statistiques par agence
     * 
     * @return array<string, array{pending: int, processing: int, done: int, failed: int}>
     */
    public function getStatsByAgency(): array
    {
        $results = $this->createQueryBuilder('j')
            ->select('j.agencyCode, j.status, COUNT(j.id) as count')
            ->groupBy('j.agencyCode, j.status')
            ->orderBy('j.agencyCode', 'ASC')
            ->getQuery()
            ->getResult();

        $stats = [];
        
        foreach ($results as $row) {
            $agency = $row['agencyCode'];
            $status = $row['status'];
            $count = (int) $row['count'];
            
            if (!isset($stats[$agency])) {
                $stats[$agency] = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
            }
            
            $stats[$agency][$status] = $count;
        }

        return $stats;
    }

    /**
     * Statistiques par type ET agence
     * 
     * @return array{pending: int, processing: int, done: int, failed: int}
     */
    public function getStatsByTypeAndAgency(string $jobType, string $agencyCode): array
    {
        $results = $this->createQueryBuilder('j')
            ->select('j.status, COUNT(j.id) as count')
            ->where('j.jobType = :type')
            ->andWhere('j.agencyCode = :agency')
            ->setParameter('type', $jobType)
            ->setParameter('agency', $agencyCode)
            ->groupBy('j.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'failed' => 0,
        ];

        foreach ($results as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Trouve les jobs en échec récents (pour alertes)
     * 
     * @return KizeoJob[]
     */
    public function findRecentFailedJobs(int $limit = 20): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status = :status')
            ->setParameter('status', KizeoJob::STATUS_FAILED)
            ->orderBy('j.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les jobs créés dans les dernières X heures
     * 
     * Utile pour monitorer l'activité récente
     */
    public function countRecentlyCreated(int $hours = 24): int
    {
        $threshold = new \DateTime("-{$hours} hours");
        
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.createdAt > :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les jobs terminés dans les dernières X heures
     */
    public function countRecentlyCompleted(int $hours = 24): int
    {
        $threshold = new \DateTime("-{$hours} hours");
        
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :status')
            ->andWhere('j.completedAt > :threshold')
            ->setParameter('status', KizeoJob::STATUS_DONE)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // =========================================================================
    // REQUÊTES UTILITAIRES
    // =========================================================================

    /**
     * Trouve tous les jobs pour un CR spécifique (form_id + data_id)
     * 
     * @return KizeoJob[]
     */
    public function findByFormData(int $formId, int $dataId): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.formId = :formId')
            ->andWhere('j.dataId = :dataId')
            ->setParameter('formId', $formId)
            ->setParameter('dataId', $dataId)
            ->orderBy('j.jobType', 'ASC')
            ->addOrderBy('j.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les jobs pour un contact/client
     * 
     * @return KizeoJob[]
     */
    public function findByContact(int $idContact, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('j')
            ->where('j.idContact = :idContact')
            ->setParameter('idContact', $idContact);
            
        if ($status !== null) {
            $qb->andWhere('j.status = :status')
               ->setParameter('status', $status);
        }
        
        return $qb->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
