<?php

namespace App\Service\Kizeo;

use App\DTO\Kizeo\ExtractedFormData;
use App\DTO\Kizeo\ExtractedMedia;
use App\Entity\Photo;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persiste les références photos dans la table `photos`.
 * 
 * Appelé dans FetchFormsCommand entre EquipmentPersister et JobCreator.
 * Crée un enregistrement Photo par équipement ayant des médias,
 * avec les noms de fichiers Kizeo dans les colonnes correspondantes.
 * 
 * La table `photos` est permanente (survit à la purge des kizeo_jobs)
 * et sert de référence pour :
 * - DownloadMediaCommand (quelles photos télécharger)
 * - ClientReportGenerator (quel chemin local pour les images)
 */
class PhotoPersister
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photoRepository,
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    /**
     * Persiste les références photos pour un CR traité.
     * 
     * @param ExtractedFormData $extractedData Données extraites du CR
     * @param string $agencyCode Code agence (S10, S40, etc.)
     * @param int $formId ID du formulaire Kizeo
     * @param int $dataId ID des données Kizeo
     * @param array<int, string> $generatedNumbers Numéros générés pour équipements hors contrat [index => numero]
     * 
     * @return array{created: int, skipped: int, photos_mapped: int}
     */
    public function persist(
        ExtractedFormData $extractedData,
        string $agencyCode,
        int $formId,
        int $dataId,
        array $generatedNumbers = []
    ): array {
        $result = [
            'created' => 0,
            'skipped' => 0,
            'photos_mapped' => 0,
        ];

        // Indexer les médias par numéro d'équipement pour accès rapide
        $mediasByEquipment = $this->indexMediasByEquipment($extractedData->medias, $generatedNumbers);

        // Déterminer visite et année depuis les équipements
        $visite = $this->resolveVisite($extractedData);
        $annee = $this->resolveAnnee($extractedData);

        // Dériver updateTime depuis dateVisite (format string pour l'entité Photo)
        $updateTime = $extractedData->dateVisite?->format('Y-m-d H:i:s');

        // 1. Traiter les équipements AU CONTRAT
        foreach ($extractedData->contractEquipments as $equipment) {
            $numero = $equipment->numero ?? null;
            if (!$numero) {
                continue;
            }

            $equipVisite = $equipment->hasValidVisite() 
                ? $equipment->getNormalizedVisite() 
                : $visite;

            $photoResult = $this->persistPhotoForEquipment(
                agencyCode: $agencyCode,
                formId: $formId,
                dataId: $dataId,
                idContact: $extractedData->idContact,
                idSociete: $extractedData->idSociete ?? '',
                raisonSociale: $extractedData->raisonSociale,
                numero: $numero,
                visite: $equipVisite,
                annee: $annee,
                updateTime: $updateTime,
                medias: $mediasByEquipment[$numero] ?? []
            );

            $result['created'] += $photoResult['created'];
            $result['skipped'] += $photoResult['skipped'];
            $result['photos_mapped'] += $photoResult['photos_mapped'];
        }

        // 2. Traiter les équipements HORS CONTRAT
        foreach ($extractedData->offContractEquipments as $index => $equipment) {
            // Le numéro a été généré par EquipmentPersister
            $numero = $generatedNumbers[$index] ?? $equipment->numero ?? null;
            if (!$numero) {
                continue;
            }

            $photoResult = $this->persistPhotoForEquipment(
                agencyCode: $agencyCode,
                formId: $formId,
                dataId: $dataId,
                idContact: $extractedData->idContact,
                idSociete: $extractedData->idSociete ?? '',
                raisonSociale: $extractedData->raisonSociale,
                numero: $numero,
                visite: $visite,
                annee: $annee,
                updateTime: $updateTime,
                medias: $mediasByEquipment[$numero] ?? []
            );

            $result['created'] += $photoResult['created'];
            $result['skipped'] += $photoResult['skipped'];
            $result['photos_mapped'] += $photoResult['photos_mapped'];
        }

        $this->kizeoLogger->info('Photos persistées', [
            'agency' => $agencyCode,
            'form_id' => $formId,
            'data_id' => $dataId,
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'photos_mapped' => $result['photos_mapped'],
        ]);

        return $result;
    }

    /**
     * Crée ou skip un enregistrement Photo pour un équipement donné.
     * 
     * @param ExtractedMedia[] $medias
     * @return array{created: int, skipped: int, photos_mapped: int}
     */
    private function persistPhotoForEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        ?int $idContact,
        string $idSociete,
        ?string $raisonSociale,
        string $numero,
        string $visite,
        string $annee,
        ?string $updateTime,
        array $medias
    ): array {
        $result = ['created' => 0, 'skipped' => 0, 'photos_mapped' => 0];

        // Déduplication : form_id + data_id + numero_equipement
        $exists = $this->photoRepository->existsByFormDataNumero(
            (string) $formId,
            (string) $dataId,
            $numero
        );

        if ($exists) {
            $result['skipped'] = 1;
            $this->kizeoLogger->debug('Photo record déjà existant', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'numero' => $numero,
            ]);
            return $result;
        }

        // Créer l'entité Photo
        $photo = new Photo();
        $photo->setCodeAgence($agencyCode);
        $photo->setFormId((string) $formId);
        $photo->setDataId((string) $dataId);
        $photo->setIdContact((string) $idContact);
        $photo->setIdSociete($idSociete);
        $photo->setNumeroEquipement($numero);
        $photo->setEquipmentId($numero); // Compatibilité legacy
        $photo->setVisite($visite);
        $photo->setAnnee($annee);
        $photo->setUpdateTime($updateTime);
        $photo->setRaisonSocialeVisite($raisonSociale);
        $photo->setDateEnregistrement(new \DateTime());

        // Mapper les médias aux colonnes photo_*
        foreach ($medias as $media) {
            $fieldName = $media->fieldName ?? null;
            $mediaName = $media->mediaName ?? null;

            if ($fieldName && $mediaName) {
                $mapped = $photo->setPhotoByFieldName($fieldName, $mediaName);
                if ($mapped) {
                    $result['photos_mapped']++;
                }
            }
        }

        $this->em->persist($photo);
        $result['created'] = 1;

        return $result;
    }

    /**
     * Indexe les médias par numéro d'équipement pour accès O(1).
     * 
     * @param ExtractedMedia[] $medias
     * @param array<int, string> $generatedNumbers
     * @return array<string, ExtractedMedia[]> [numero => [media, ...]]
     */
    private function indexMediasByEquipment(array $medias, array $generatedNumbers): array
    {
        $indexed = [];

        foreach ($medias as $media) {
            $numero = $media->equipmentNumero ?? null;

            // Si pas de numéro direct, essayer via generatedNumbers (hors contrat)
            if (!$numero && $media->equipmentIndex !== null && isset($generatedNumbers[$media->equipmentIndex])) {
                $numero = $generatedNumbers[$media->equipmentIndex];
            }

            if ($numero) {
                $indexed[$numero][] = $media;
            }
        }

        return $indexed;
    }

    /**
     * Résout la visite depuis les données extraites.
     */
    private function resolveVisite(ExtractedFormData $extractedData): string
    {
        foreach ($extractedData->contractEquipments as $equipment) {
            if ($equipment->hasValidVisite()) {
                return $equipment->getNormalizedVisite();
            }
        }
        return 'CE1';
    }

    /**
     * Résout l'année depuis les données extraites.
     */
    private function resolveAnnee(ExtractedFormData $extractedData): string
    {
        // Utiliser l'année déjà extraite dans le DTO si disponible
        if ($extractedData->annee) {
            return $extractedData->annee;
        }

        // Sinon dériver depuis dateVisite (DateTimeInterface)
        if ($extractedData->dateVisite) {
            return $extractedData->dateVisite->format('Y');
        }

        return date('Y');
    }
}