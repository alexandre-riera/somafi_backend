<?php

namespace App\Service\Kizeo;

use App\Repository\AgencyRepository;
use App\Service\Equipment\EquipmentFactory;
use App\Service\Equipment\EquipmentDeduplicator;
use App\Service\Equipment\OffContractNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service principal de traitement des formulaires Kizeo
 * 
 * Responsabilités:
 * - Récupère les formulaires non lus via KizeoApiService
 * - Parse le JSON et extrait les équipements (contrat + hors contrat)
 * - Déduplique avant enregistrement
 * - Marque les formulaires comme lus
 */
class KizeoFormProcessor
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly EquipmentDeduplicator $deduplicator,
        private readonly OffContractNumberGenerator $numberGenerator,
        private readonly AgencyRepository $agencyRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $kizeoLogger,
        private readonly array $kizeoFormIds,
    ) {
    }

    /**
     * Traite toutes les agences configurées
     * 
     * @return array<string, array<string, mixed>>
     */
    public function processAllAgencies(int $limit = 50, bool $dryRun = false): array
    {
        $results = [];
        $agencies = $this->agencyRepository->findWithKizeoForm();

        foreach ($agencies as $agency) {
            $results[$agency->getCode()] = $this->processAgency($agency->getCode(), $limit, $dryRun);
        }

        return $results;
    }

    /**
     * Traite une agence spécifique
     * 
     * @return array<string, mixed>
     */
    public function processAgency(string $agencyCode, int $limit = 50, bool $dryRun = false): array
    {
        $result = [
            'success' => true,
            'forms_processed' => 0,
            'contract_created' => 0,
            'contract_updated' => 0,
            'offcontract_created' => 0,
            'offcontract_skipped' => 0,
            'photos_saved' => 0,
            'errors' => 0,
        ];

        // Récupérer le form_id Kizeo pour cette agence
        $formId = $this->kizeoFormIds[$agencyCode] ?? null;
        
        if (!$formId) {
            $this->kizeoLogger->warning('Pas de form_id configuré pour l\'agence', [
                'agency' => $agencyCode,
            ]);
            $result['success'] = false;
            $result['error'] = 'Pas de form_id Kizeo configuré';
            return $result;
        }

        $this->kizeoLogger->info('Début traitement agence', [
            'agency' => $agencyCode,
            'form_id' => $formId,
        ]);

        // Récupérer les formulaires non lus
        $forms = $this->kizeoApi->getUnreadForms($formId, $limit);

        if (empty($forms)) {
            $this->kizeoLogger->info('Aucun formulaire à traiter', ['agency' => $agencyCode]);
            return $result;
        }

        foreach ($forms as $formData) {
            try {
                $formResult = $this->processForm($agencyCode, $formId, $formData, $dryRun);
                
                $result['forms_processed']++;
                $result['contract_created'] += $formResult['contract_created'];
                $result['contract_updated'] += $formResult['contract_updated'];
                $result['offcontract_created'] += $formResult['offcontract_created'];
                $result['offcontract_skipped'] += $formResult['offcontract_skipped'];
                $result['photos_saved'] += $formResult['photos_saved'];

            } catch (\Exception $e) {
                $result['errors']++;
                $this->kizeoLogger->error('Erreur traitement formulaire', [
                    'agency' => $agencyCode,
                    'data_id' => $formData['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Traite un formulaire individuel
     * 
     * @param array<string, mixed> $formData
     * @return array<string, int>
     */
    private function processForm(string $agencyCode, int $formId, array $formData, bool $dryRun): array
    {
        $result = [
            'contract_created' => 0,
            'contract_updated' => 0,
            'offcontract_created' => 0,
            'offcontract_skipped' => 0,
            'photos_saved' => 0,
        ];

        $dataId = $formData['id'];
        $fields = $formData['fields'] ?? [];

        // Extraire les informations communes
        $idContact = $this->extractFieldValue($fields, 'id_client_');
        $dateVisite = $this->extractFieldValue($fields, 'date_et_heure1');
        $trigramme = $this->extractFieldValue($fields, 'trigramme');

        if (!$idContact) {
            $this->kizeoLogger->warning('Formulaire sans id_contact', [
                'data_id' => $dataId,
            ]);
            return $result;
        }

        // 1. Traiter les équipements AU CONTRAT
        $contractEquipments = $fields['contrat_de_maintenance']['value'] ?? [];
        foreach ($contractEquipments as $equipData) {
            $contractResult = $this->processContractEquipment(
                $agencyCode,
                $formId,
                $dataId,
                $idContact,
                $dateVisite,
                $trigramme,
                $equipData,
                $dryRun
            );
            $result['contract_created'] += $contractResult['created'];
            $result['contract_updated'] += $contractResult['updated'];
        }

        // 2. Traiter les équipements HORS CONTRAT
        $offContractEquipments = $fields['tableau2']['value'] ?? [];
        foreach ($offContractEquipments as $index => $equipData) {
            $offContractResult = $this->processOffContractEquipment(
                $agencyCode,
                $formId,
                $dataId,
                $index,
                $idContact,
                $dateVisite,
                $trigramme,
                $equipData,
                $dryRun
            );
            $result['offcontract_created'] += $offContractResult['created'];
            $result['offcontract_skipped'] += $offContractResult['skipped'];
        }

        // 3. Marquer le formulaire comme lu (CRITIQUE pour éviter les doublons)
        if (!$dryRun) {
            $this->kizeoApi->markAsRead($formId, $dataId);
        }

        return $result;
    }

    /**
     * Traite un équipement AU CONTRAT
     * 
     * @param array<string, mixed> $equipData
     * @return array<string, int>
     */
    private function processContractEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $idContact,
        ?string $dateVisite,
        ?string $trigramme,
        array $equipData,
        bool $dryRun
    ): array {
        $result = ['created' => 0, 'updated' => 0];

        // Extraire le numéro d'équipement
        $numero = $equipData['equipement']['value'] ?? null;
        if (!$numero) {
            return $result;
        }

        // Extraire la visite depuis le path (ex: "CLIENT\\CE1" -> "CE1")
        $visite = $this->extractVisiteFromPath($equipData['equipement']['path'] ?? '');
        $annee = $dateVisite ? (new \DateTime($dateVisite))->format('Y') : date('Y');

        // Vérifier la déduplication
        $exists = $this->deduplicator->existsContractEquipment(
            $agencyCode,
            $idContact,
            $numero,
            $visite,
            $dateVisite ? new \DateTime($dateVisite) : null
        );

        if ($exists) {
            // TODO: Logique de mise à jour si nécessaire
            return $result;
        }

        // Créer l'entité
        $entity = $this->equipmentFactory->createForAgency($agencyCode);
        
        $entity->setIdContact($idContact);
        $entity->setNumeroEquipement($numero);
        $entity->setLibelleEquipement($equipData['libelle_produit']['value'] ?? '');
        $entity->setVisite($visite);
        $entity->setAnnee($annee);
        $entity->setDateDerniereVisite($dateVisite ? new \DateTime($dateVisite) : null);
        $entity->setMarque($equipData['marque']['value'] ?? null);
        $entity->setModeFonctionnement($equipData['mode_fonctionnement']['value'] ?? null);
        $entity->setStatutEquipement($equipData['etat_']['value'] ?? null);
        $entity->setTrigrammeTech($trigramme);
        $entity->setIsHorsContrat(false);
        $entity->setKizeoFormId($formId);
        $entity->setKizeoDataId($dataId);

        if (!$dryRun) {
            $this->em->persist($entity);
        }

        $result['created'] = 1;
        return $result;
    }

    /**
     * Traite un équipement HORS CONTRAT
     * 
     * @param array<string, mixed> $equipData
     * @return array<string, int>
     */
    private function processOffContractEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $index,
        int $idContact,
        ?string $dateVisite,
        ?string $trigramme,
        array $equipData,
        bool $dryRun
    ): array {
        $result = ['created' => 0, 'skipped' => 0];

        // DÉDUPLICATION CRITIQUE: form_id + data_id + index
        $exists = $this->deduplicator->existsOffContractEquipment(
            $agencyCode,
            $formId,
            $dataId,
            $index
        );

        if ($exists) {
            $this->kizeoLogger->debug('Équipement HC ignoré (doublon)', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'index' => $index,
            ]);
            $result['skipped'] = 1;
            return $result;
        }

        // Extraire le type/libellé
        $libelle = $equipData['libelle_produit_hc']['value'] ?? $equipData['type_equipement']['value'] ?? '';
        
        // Générer le numéro (dernier de même type + 1)
        $numero = $this->numberGenerator->generate($agencyCode, $idContact, $libelle);

        // Extraire la visite (peut être dans un champ différent pour HC)
        $visite = $equipData['visite']['value'] ?? 'CE1';
        $annee = $dateVisite ? (new \DateTime($dateVisite))->format('Y') : date('Y');

        // Créer l'entité
        $entity = $this->equipmentFactory->createForAgency($agencyCode);
        
        $entity->setIdContact($idContact);
        $entity->setNumeroEquipement($numero);
        $entity->setLibelleEquipement($libelle);
        $entity->setVisite($visite);
        $entity->setAnnee($annee);
        $entity->setDateDerniereVisite($dateVisite ? new \DateTime($dateVisite) : null);
        $entity->setMarque($equipData['marque']['value'] ?? null);
        $entity->setStatutEquipement($equipData['etat_']['value'] ?? null);
        $entity->setTrigrammeTech($trigramme);
        $entity->setIsHorsContrat(true);
        $entity->setKizeoFormId($formId);
        $entity->setKizeoDataId($dataId);
        $entity->setKizeoIndex($index);

        if (!$dryRun) {
            $this->em->persist($entity);
        }

        $this->kizeoLogger->info('Équipement HC créé', [
            'numero' => $numero,
            'id_contact' => $idContact,
        ]);

        $result['created'] = 1;
        return $result;
    }

    /**
     * Extrait une valeur d'un champ Kizeo
     * 
     * @param array<string, mixed> $fields
     */
    private function extractFieldValue(array $fields, string $fieldName): mixed
    {
        return $fields[$fieldName]['value'] ?? null;
    }

    /**
     * Extrait la visite depuis le path d'un équipement
     * Ex: "GROUPE MAURIN\\CE1" -> "CE1"
     */
    private function extractVisiteFromPath(string $path): string
    {
        if (empty($path)) {
            return 'CE1';
        }

        $parts = explode('\\', $path);
        $lastPart = end($parts);

        // Vérifier que c'est bien un code visite valide
        if (in_array($lastPart, ['CEA', 'CE1', 'CE2', 'CE3', 'CE4'])) {
            return $lastPart;
        }

        return 'CE1';
    }
}
