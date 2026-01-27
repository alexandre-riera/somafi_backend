<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use App\DTO\Kizeo\ExtractedFormData;
use App\DTO\Kizeo\ExtractedMedia;
use App\Entity\KizeoJob;
use App\Repository\KizeoJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de création des jobs photo/PDF dans la file d'attente
 * 
 * Responsabilités :
 * - Créer les jobs PDF pour chaque CR
 * - Créer les jobs photo pour chaque média
 * - Éviter les doublons (vérification avant insertion)
 * - Assigner les numéros d'équipement corrects aux photos hors contrat
 */
class JobCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KizeoJobRepository $jobRepository,
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    /**
     * Crée tous les jobs (PDF + photos) pour un CR
     * 
     * @param ExtractedFormData $formData Données extraites du CR
     * @param string $agencyCode Code agence (S10, S60, etc.)
     * @param array<int, string> $generatedNumbers Mapping kizeoIndex => numero (pour hors contrat)
     * 
     * @return array{pdf_created: bool, photos_created: int, photos_skipped: int}
     */
    public function createJobs(
        ExtractedFormData $formData,
        string $agencyCode,
        array $generatedNumbers = []
    ): array {
        $stats = [
            'pdf_created' => false,
            'photos_created' => 0,
            'photos_skipped' => 0,
        ];

        if ($formData->idContact === null || $formData->annee === null) {
            $this->kizeoLogger->warning('Impossible de créer jobs sans idContact ou année', [
                'form_id' => $formData->formId,
                'data_id' => $formData->dataId,
            ]);
            return $stats;
        }

        // 1. Créer le job PDF
        $stats['pdf_created'] = $this->createPdfJob($formData, $agencyCode);

        // 2. Créer les jobs photos
        foreach ($formData->medias as $media) {
            $created = $this->createPhotoJob($formData, $agencyCode, $media, $generatedNumbers);
            
            if ($created) {
                $stats['photos_created']++;
            } else {
                $stats['photos_skipped']++;
            }
        }

        // Flush tous les jobs créés
        $this->entityManager->flush();

        $this->kizeoLogger->info('Jobs créés', [
            'form_id' => $formData->formId,
            'data_id' => $formData->dataId,
            'agency' => $agencyCode,
            ...$stats,
        ]);

        return $stats;
    }

    /**
     * Crée un job PDF pour le CR
     * 
     * @return bool True si créé, False si déjà existant
     */
    private function createPdfJob(
        ExtractedFormData $formData,
        string $agencyCode
    ): bool {
        // Vérifier si le job existe déjà
        if ($this->jobRepository->pdfJobExists($formData->formId, $formData->dataId)) {
            $this->kizeoLogger->debug('Job PDF déjà existant', [
                'form_id' => $formData->formId,
                'data_id' => $formData->dataId,
            ]);
            return false;
        }

        // Déterminer la visite (prendre la première trouvée dans les équipements)
        $visite = $this->determineVisiteFromEquipments($formData);

        $job = KizeoJob::createPdfJob(
            agencyCode: $agencyCode,
            formId: $formData->formId,
            dataId: $formData->dataId,
            idContact: $formData->idContact,
            annee: $formData->annee,
            visite: $visite,
            clientName: $formData->getSanitizedClientName(),
        );

        $this->entityManager->persist($job);

        $this->kizeoLogger->debug('Job PDF créé', [
            'form_id' => $formData->formId,
            'data_id' => $formData->dataId,
            'visite' => $visite,
        ]);

        return true;
    }

    /**
     * Crée un job photo pour un média
     * 
     * @param array<int, string> $generatedNumbers Mapping kizeoIndex => numero (pour hors contrat)
     * @return bool True si créé, False si déjà existant
     */
    private function createPhotoJob(
        ExtractedFormData $formData,
        string $agencyCode,
        ExtractedMedia $media,
        array $generatedNumbers
    ): bool {
        // Vérifier si le job existe déjà
        if ($this->jobRepository->photoJobExists(
            $formData->formId,
            $formData->dataId,
            $media->mediaName
        )) {
            $this->kizeoLogger->debug('Job photo déjà existant', [
                'form_id' => $formData->formId,
                'data_id' => $formData->dataId,
                'media_name' => $media->mediaName,
            ]);
            return false;
        }

        // Résoudre le numéro d'équipement
        $equipmentNumero = $this->resolveEquipmentNumero($media, $generatedNumbers);

        // Déterminer la visite
        $visite = $this->determineVisiteFromEquipments($formData);

        $job = KizeoJob::createPhotoJob(
            agencyCode: $agencyCode,
            formId: $formData->formId,
            dataId: $formData->dataId,
            mediaName: $media->mediaName,
            equipmentNumero: $equipmentNumero,
            idContact: $formData->idContact,
            annee: $formData->annee,
            visite: $visite,
        );

        $this->entityManager->persist($job);

        $this->kizeoLogger->debug('Job photo créé', [
            'form_id' => $formData->formId,
            'data_id' => $formData->dataId,
            'media_name' => $media->mediaName,
            'equipment_numero' => $equipmentNumero,
        ]);

        return true;
    }

    /**
     * Résout le numéro d'équipement pour une photo
     * 
     * Pour les photos hors contrat, le numéro est récupéré depuis le mapping
     * généré par EquipmentPersister.
     */
    private function resolveEquipmentNumero(
        ExtractedMedia $media,
        array $generatedNumbers
    ): ?string {
        $numero = $media->equipmentNumero;

        if ($numero === null) {
            return null;
        }

        // Si c'est un placeholder hors contrat (HC_0, HC_1, etc.)
        if (str_starts_with($numero, 'HC_')) {
            $index = (int) substr($numero, 3);
            return $generatedNumbers[$index] ?? $numero;
        }

        return $numero;
    }

    /**
     * Détermine la visite principale depuis les équipements
     */
    private function determineVisiteFromEquipments(ExtractedFormData $formData): string
    {
        // Chercher dans les équipements au contrat
        foreach ($formData->contractEquipments as $equipment) {
            if ($equipment->hasValidVisite()) {
                return $equipment->getNormalizedVisite();
            }
        }

        // Défaut
        return 'CE1';
    }

    /**
     * Crée uniquement les jobs photos pour un CR
     * (utile pour retraitement)
     */
    public function createPhotoJobsOnly(
        ExtractedFormData $formData,
        string $agencyCode,
        array $generatedNumbers = []
    ): array {
        $stats = [
            'photos_created' => 0,
            'photos_skipped' => 0,
        ];

        if ($formData->idContact === null || $formData->annee === null) {
            return $stats;
        }

        foreach ($formData->medias as $media) {
            $created = $this->createPhotoJob($formData, $agencyCode, $media, $generatedNumbers);
            
            if ($created) {
                $stats['photos_created']++;
            } else {
                $stats['photos_skipped']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * Crée uniquement le job PDF pour un CR
     * (utile pour retraitement)
     */
    public function createPdfJobOnly(
        ExtractedFormData $formData,
        string $agencyCode
    ): bool {
        if ($formData->idContact === null || $formData->annee === null) {
            return false;
        }

        $created = $this->createPdfJob($formData, $agencyCode);
        
        if ($created) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * Compte les jobs en attente pour monitoring
     */
    public function getPendingCounts(): array
    {
        $pdfStats = $this->jobRepository->getStatsByType(KizeoJob::TYPE_PDF);
        $photoStats = $this->jobRepository->getStatsByType(KizeoJob::TYPE_PHOTO);

        return [
            'pdf' => [
                'pending' => $pdfStats['pending'],
                'processing' => $pdfStats['processing'],
                'done' => $pdfStats['done'],
                'failed' => $pdfStats['failed'],
            ],
            'photo' => [
                'pending' => $photoStats['pending'],
                'processing' => $photoStats['processing'],
                'done' => $photoStats['done'],
                'failed' => $photoStats['failed'],
            ],
        ];
    }
}
