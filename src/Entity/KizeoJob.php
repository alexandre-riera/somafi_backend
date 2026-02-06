<?php

namespace App\Entity;

use App\Repository\KizeoJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entité représentant un job de téléchargement (photo ou PDF)
 * 
 * Cette table remplace RabbitMQ/Redis sur O2switch mutualisé.
 * Les CRON interrogent cette table pour traiter les téléchargements en file d'attente.
 */
#[ORM\Entity(repositoryClass: KizeoJobRepository::class)]
#[ORM\Table(name: 'kizeo_jobs')]
#[ORM\Index(name: 'idx_pending_type_priority', columns: ['status', 'job_type', 'priority', 'created_at'])]
#[ORM\Index(name: 'idx_form_data', columns: ['form_id', 'data_id'])]
#[ORM\Index(name: 'idx_cleanup', columns: ['status', 'completed_at'])]
#[ORM\Index(name: 'idx_agency', columns: ['agency_code', 'status'])]
#[ORM\Index(name: 'idx_stuck', columns: ['status', 'started_at'])]
#[ORM\UniqueConstraint(name: 'uk_photo', columns: ['form_id', 'data_id', 'media_name'])]
#[ORM\HasLifecycleCallbacks]
class KizeoJob
{
    // =========================================================================
    // CONSTANTES
    // =========================================================================
    
    public const TYPE_PHOTO = 'photo';
    public const TYPE_PDF = 'pdf';
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    
    public const PRIORITY_URGENT = 1;    // PDF (prioritaire)
    public const PRIORITY_NORMAL = 5;    // Photos standard
    public const PRIORITY_LOW = 10;      // Basse priorité
    
    public const MAX_ATTEMPTS = 3;
    
    // =========================================================================
    // PROPRIÉTÉS
    // =========================================================================
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * Type de job : 'photo' ou 'pdf'
     */
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $jobType;

    /**
     * Code agence (S10, S40, S50, etc.)
     */
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $agencyCode;

    /**
     * ID du formulaire Kizeo
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $formId;

    /**
     * ID des données Kizeo (data_id du CR technicien)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $dataId;

    /**
     * Nom du fichier média sur Kizeo (uniquement pour les photos)
     * Ex: photo_generale_1.jpg, photo3_1.jpg
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $mediaName = null;

    /**
     * Numéro de l'équipement (pour nommer le fichier local)
     * Ex: RAP01, SEC02, NIV03
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $equipmentNumero = null;

    /**
     * ID du contact/client (clé de liaison)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $idContact;

    /**
     * Année de la visite
     */
    #[ORM\Column(type: Types::STRING, length: 4)]
    private string $annee;

    /**
     * Type de visite (CEA, CE1, CE2, CE3, CE4)
     */
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $visite;

    /**
     * Nom du client (pour nommer le PDF)
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $clientName = null;
    
    /**
     * Date de la visite (extrait du CR technicien, format YYYYMMDD) - optionnel, peut être dérivé de dataId
     */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $dateVisite = null;

    /**
     * Statut du job : pending, processing, done, failed
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_PENDING;

    /**
     * Priorité (1=urgent, 5=normal, 10=basse)
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $priority = self::PRIORITY_NORMAL;

    /**
     * Nombre de tentatives effectuées
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $attempts = 0;

    /**
     * Nombre maximum de tentatives avant échec définitif
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $maxAttempts = self::MAX_ATTEMPTS;

    /**
     * Dernière erreur rencontrée
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /**
     * Chemin local du fichier téléchargé
     */
    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $localPath = null;

    /**
     * Taille du fichier en octets
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $fileSize = null;

    /**
     * Date de création du job
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    /**
     * Date de début du traitement
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    /**
     * Date de fin du traitement
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    // =========================================================================
    // CONSTRUCTEUR
    // =========================================================================

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // =========================================================================
    // FACTORY METHODS
    // =========================================================================

    /**
     * Crée un job de téléchargement de photo
     */
    public static function createPhotoJob(
        string $agencyCode,
        int $formId,
        int $dataId,
        string $mediaName,
        ?string $equipmentNumero,
        int $idContact,
        string $annee,
        string $visite
    ): self {
        $job = new self();
        $job->jobType = self::TYPE_PHOTO;
        $job->agencyCode = strtoupper($agencyCode);
        $job->formId = $formId;
        $job->dataId = $dataId;
        $job->mediaName = $mediaName;
        $job->equipmentNumero = $equipmentNumero;
        $job->idContact = $idContact;
        $job->annee = $annee;
        $job->visite = strtoupper($visite);
        $job->priority = self::PRIORITY_NORMAL;
        
        return $job;
    }

    /**
     * Crée un job de téléchargement de PDF
     */
    public static function createPdfJob(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $idContact,
        string $annee,
        string $visite,
        ?string $clientName = null,
        ?string $dateVisite = null  // ← NOUVEAU (optionnel pour rétrocompat)
    ): self {
        $job = new self();
        $job->jobType = self::TYPE_PDF;
        $job->agencyCode = strtoupper($agencyCode);
        $job->formId = $formId;
        $job->dataId = $dataId;
        $job->idContact = $idContact;
        $job->annee = $annee;
        $job->visite = strtoupper($visite);
        $job->clientName = $clientName;
        $job->dateVisite = $dateVisite;  // ← NOUVEAU
        $job->priority = self::PRIORITY_URGENT;
        
        return $job;
    }

    // =========================================================================
    // STATE TRANSITIONS
    // =========================================================================

    /**
     * Marque le job comme en cours de traitement
     */
    public function markAsProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->startedAt = new \DateTime();
        $this->attempts++;
    }

    /**
     * Marque le job comme terminé avec succès
     */
    public function markAsDone(string $localPath, int $fileSize): void
    {
        $this->status = self::STATUS_DONE;
        $this->localPath = $localPath;
        $this->fileSize = $fileSize;
        $this->completedAt = new \DateTime();
        $this->lastError = null;
    }

    /**
     * Marque le job comme échoué
     * 
     * Si le nombre max de tentatives n'est pas atteint, 
     * le job repasse en pending pour retry
     */
    public function markAsFailed(string $error): void
    {
        $this->lastError = $error;
        
        if ($this->attempts >= $this->maxAttempts) {
            $this->status = self::STATUS_FAILED;
            $this->completedAt = new \DateTime();
        } else {
            // Retry au prochain CRON
            $this->status = self::STATUS_PENDING;
        }
    }

    /**
     * Reset un job bloqué (processing depuis trop longtemps)
     */
    public function resetForRetry(): void
    {
        $this->status = self::STATUS_PENDING;
        $this->startedAt = null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Vérifie si le job peut encore être retenté
     */
    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    /**
     * Vérifie si c'est un job photo
     */
    public function isPhoto(): bool
    {
        return $this->jobType === self::TYPE_PHOTO;
    }

    /**
     * Vérifie si c'est un job PDF
     */
    public function isPdf(): bool
    {
        return $this->jobType === self::TYPE_PDF;
    }

    /**
     * Génère le chemin de stockage local attendu
     * 
     * Structure: {agence}/{id_contact}/{annee}/{visite}/
     */
    public function getExpectedStoragePath(): string
    {
        return sprintf(
            '%s/%d/%s/%s',
            $this->agencyCode,
            $this->idContact,
            $this->annee,
            $this->visite
        );
    }

    /**
     * Génère le nom de fichier local pour une photo
     * 
     * Convention:
     * - Équipement au contrat: {numero}_generale.jpg
     * - Équipement hors contrat: {numero}_compte_rendu.jpg
     */
    public function getExpectedPhotoFilename(bool $isHorsContrat = false): ?string
    {
        if (!$this->isPhoto() || !$this->equipmentNumero) {
            return null;
        }
        
        $suffix = $isHorsContrat ? 'compte_rendu' : 'generale';
        return sprintf('%s_%s.jpg', $this->equipmentNumero, $suffix);
    }

    /**
     * Génère le nom de fichier local pour un PDF
     * 
     * Convention: {client}-{date}-{visite}.pdf
     */
    public function getExpectedPdfFilename(): ?string
    {
        if (!$this->isPdf()) {
            return null;
        }
        
        $clientSlug = $this->clientName 
            ? preg_replace('/[^a-zA-Z0-9]+/', '_', $this->clientName)
            : 'client_' . $this->idContact;
            
        return sprintf(
            '%s-%s-%s.pdf',
            $clientSlug,
            date('Y-m-d'),
            $this->visite
        );
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }

    public function getAgencyCode(): string
    {
        return $this->agencyCode;
    }

    public function getFormId(): int
    {
        return $this->formId;
    }

    public function getDataId(): int
    {
        return $this->dataId;
    }

    public function getMediaName(): ?string
    {
        return $this->mediaName;
    }

    public function getEquipmentNumero(): ?string
    {
        return $this->equipmentNumero;
    }

    public function getIdContact(): int
    {
        return $this->idContact;
    }

    public function getAnnee(): string
    {
        return $this->annee;
    }

    public function getVisite(): string
    {
        return $this->visite;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function getDateVisite(): ?string
    {
        return $this->dateVisite;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLocalPath(): ?string
    {
        return $this->localPath;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    // =========================================================================
    // SETTERS (limités - préférer les factory methods et state transitions)
    // =========================================================================

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function setMaxAttempts(int $maxAttempts): static
    {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }

    public function setClientName(?string $clientName): static
    {
        $this->clientName = $clientName;
        return $this;
    }

    public function setEquipmentNumero(?string $equipmentNumero): static
    {
        $this->equipmentNumero = $equipmentNumero;
        return $this;
    }

}
