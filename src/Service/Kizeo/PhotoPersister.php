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
 * 
 * CORRECTIONS 06/02/2026:
 * - FIX #1 CRITIQUE: $equipment->numero → $equipment->numeroEquipement
 *   (propriété inexistante sur ExtractedEquipment → skip systématique AU CONTRAT)
 * - FIX #2: Mapping aliases champs HC vers colonnes table photos
 *   (photo_etiquette_somafi1 → photo_etiquette_somafi, photo3 → photo_compte_rendu, etc.)
 * - FIX #3: Logging enrichi pour tracer les photos non mappées
 * 
 * CORRECTIONS 07/02/2026:
 * - FIX #4: indexMediasByEquipment — résolution HC_x via generatedNumbers
 *   (les medias HC ont equipmentNumero = "HC_0", pas le numéro réel)
 * - FIX #5: Guard défensif — skip silencieux si HC sans numéro résolu
 *   au lieu d'un warning bloquant
 */
class PhotoPersister
{
    /**
     * Mapping des noms de champs Kizeo vers les colonnes réelles de la table `photos`.
     * 
     * Les champs du sous-formulaire "tableau2" (hors contrat) ont des noms 
     * DIFFÉRENTS de ceux de "contrat_de_maintenance" (au contrat).
     * Ce mapping normalise les deux vers les colonnes existantes.
     * 
     * Également utile pour les champs au contrat dont le nom Kizeo
     * diffère légèrement du nom de colonne (ex: photo2 → photo_2).
     */
    private const FIELD_ALIASES = [
        // =====================================================================
        // Aliases HORS CONTRAT (tableau2) → colonnes table photos
        // =====================================================================
        'photo_etiquette_somafi1'    => 'photo_etiquette_somafi',   // HC: suffixe "1"
        'photo_plaque_signaletique'  => 'photo_plaque',             // HC: nom différent
        'photo3'                     => 'photo_compte_rendu',       // HC: photo CR = photo3
        'photo_anomalie'             => 'photo_choc',               // HC: anomalie → choc (colonne la plus proche)

        // =====================================================================
        // Aliases AU CONTRAT (contrat_de_maintenance) → colonnes table photos
        // =====================================================================
        'photo2'                             => 'photo_2',                          // Contrat: photo CR = photo2
        'photo_environnement_equipemen1'     => 'photo_environnement_equipement1',  // Contrat: troncature Kizeo
        'photo_envirronement_eclairage'      => 'photo_envirronement_eclairage',    // Déjà correct (typo dans la BDD aussi)
        'photo_environnement_eclairage'      => 'photo_envirronement_eclairage',    // Variante sans typo → colonne avec typo
        'photo_lame_basse_int_ext'           => 'photo_lame_basse__int_ext',        // Contrat: double underscore en BDD
        'photo_bache_'                       => 'photo_bache',                      // Contrat: underscore trailing

        // Noms tronqués par Kizeo (limite 30 chars probable)
        'photo_complementaire_equipeme'  => 'photo_complementaire_equipement',
        'photo_feuille_prise_de_cote_'   => 'photo_feuille_prise_de_cote',
    ];

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

        // =====================================================================
        // 1. Traiter les équipements AU CONTRAT
        // FIX #1: $equipment->numero → $equipment->numeroEquipement
        // (ExtractedEquipment n'a PAS de propriété "numero", c'est "numeroEquipement")
        // =====================================================================
        foreach ($extractedData->contractEquipments as $equipment) {
            $numero = $equipment->numeroEquipement ?? null;
            if (!$numero) {
                $this->kizeoLogger->warning('Équipement AU CONTRAT sans numéro, skip photo', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'equipment_type' => $equipment->typeEquipement ?? 'inconnu',
                ]);
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
                medias: $mediasByEquipment[$numero] ?? [],
                isContract: true,
            );

            $result['created'] += $photoResult['created'];
            $result['skipped'] += $photoResult['skipped'];
            $result['photos_mapped'] += $photoResult['photos_mapped'];
        }

        // =====================================================================
        // 2. Traiter les équipements HORS CONTRAT
        // FIX #1: $equipment->numero → $equipment->numeroEquipement
        // FIX #5 (07/02/2026): Guard défensif pour HC sans numéro résolu
        // =====================================================================
        foreach ($extractedData->offContractEquipments as $index => $equipment) {
            // Le numéro a été généré par EquipmentPersister (priorité)
            // FIX #5: generatedNumbers contient maintenant aussi les numéros des HC dédupliqués
            $numero = $generatedNumbers[$index] ?? $equipment->numeroEquipement ?? null;

            if (!$numero) {
                // HC sans numéro résolu — skip silencieux (debug au lieu de warning)
                $this->kizeoLogger->debug('Équipement HC sans numéro résolu, skip photo (attendu si HC non persisté)', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'index' => $index,
                    'generated_numbers_keys' => array_keys($generatedNumbers),
                ]);
                continue;
            }

            // FIX #5: Vérifier que le numéro n'est pas un placeholder HC_x non résolu
            if (str_starts_with($numero, 'HC_')) {
                $this->kizeoLogger->debug('Équipement HC avec placeholder non résolu, skip photo', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'index' => $index,
                    'placeholder' => $numero,
                ]);
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
                medias: $mediasByEquipment[$numero] ?? [],
                isContract: false,
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
     * @param bool $isContract Indique si l'équipement est au contrat (pour le logging)
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
        array $medias,
        bool $isContract = true,
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

        // =====================================================================
        // FIX #2: Mapper les médias aux colonnes photo_* AVEC résolution d'aliases
        // Les champs HC ont des noms différents des colonnes (ex: photo_etiquette_somafi1)
        // =====================================================================
        foreach ($medias as $media) {
            $fieldName = $media->fieldName ?? null;
            $mediaName = $media->mediaName ?? null;

            if (!$fieldName || !$mediaName) {
                continue;
            }

            // Résoudre l'alias : nom champ Kizeo → nom colonne table photos
            $resolvedFieldName = $this->resolveFieldAlias($fieldName);

            $mapped = $photo->setPhotoByFieldName($resolvedFieldName, $mediaName);
            if ($mapped) {
                $result['photos_mapped']++;
            } else {
                // FIX #3: Logger les photos non mappées pour diagnostic
                $this->kizeoLogger->debug('Photo non mappée vers colonne', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'numero' => $numero,
                    'field_name_original' => $fieldName,
                    'field_name_resolved' => $resolvedFieldName,
                    'media_name' => $mediaName,
                    'is_contract' => $isContract,
                ]);
            }
        }

        $this->em->persist($photo);
        $result['created'] = 1;

        return $result;
    }

    /**
     * Résout un alias de champ Kizeo vers le nom de colonne réel de la table `photos`.
     * 
     * Les sous-formulaires contrat_de_maintenance et tableau2 utilisent des noms
     * de champs légèrement différents pour des données équivalentes.
     * Ce mapping unifie les deux vers les colonnes existantes.
     * 
     * @param string $fieldName Nom du champ Kizeo brut
     * @return string Nom de colonne résolu pour setPhotoByFieldName()
     */
    private function resolveFieldAlias(string $fieldName): string
    {
        return self::FIELD_ALIASES[$fieldName] ?? $fieldName;
    }

    /**
     * Indexe les médias par numéro d'équipement pour accès O(1).
     * 
     * FIX #4 (07/02/2026): Résolution complète des placeholders HC_x
     * Les medias HC ont equipmentNumero = "HC_0", "HC_1"... qu'il faut
     * résoudre via generatedNumbers AVANT l'indexation. Sans ça, les medias
     * HC se retrouvent indexés sous "HC_0" au lieu du vrai numéro (RID28, etc.)
     * et ne sont jamais rattachés à l'équipement lors du persist.
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

            // FIX #4: Résoudre les placeholders HC_x → numéro réel
            if ($numero !== null && str_starts_with($numero, 'HC_')) {
                $hcIndex = (int) substr($numero, 3);

                if (isset($generatedNumbers[$hcIndex])) {
                    $numero = $generatedNumbers[$hcIndex];
                } else {
                    // Placeholder non résolu — essayer via equipmentIndex en fallback
                    $numero = null;
                }
            }

            // Fallback via equipmentIndex (si pas de numéro direct ou HC non résolu)
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