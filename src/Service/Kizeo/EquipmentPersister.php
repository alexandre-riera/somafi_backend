<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use App\DTO\Kizeo\ExtractedEquipment;
use App\DTO\Kizeo\ExtractedFormData;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service de persistance des équipements avec déduplication
 * 
 * Responsabilités :
 * - Vérifier si un équipement existe déjà (déduplication)
 * - Insérer les nouveaux équipements
 * - Générer les numéros pour équipements hors contrat
 * 
 * CORRECTION 30/01/2026:
 * - 'source' → 'is_hors_contrat' (0 = contrat, 1 = hors contrat)
 * - 'created_at' → 'date_enregistrement'
 * - 'trigramme_technicien' → 'trigramme_tech'
 */
class EquipmentPersister
{
    // Mapping des préfixes par type d'équipement
    private const TYPE_PREFIXES = [
        'porte sectionnelle' => 'SEC',
        'sectionnelle' => 'SEC',
        'porte rapide' => 'RAP',
        'rapide' => 'RAP',
        'rideau metallique' => 'RID',
        'rideau métallique' => 'RID',
        'rideau' => 'RID',
        'portail' => 'PAU',
        'barriere' => 'BLE',
        'barrière' => 'BLE',
        'niveleur de quai' => 'NIV',
        'niveleur' => 'NIV',
        'quai' => 'NIV',
        'porte coupe feu' => 'CFE',
        'coupe feu' => 'CFE',
        'coupe-feu' => 'CFE',
        'porte automatique' => 'PAU',
        'automatique' => 'PAU',
        'porte pieton' => 'PPV',
        'porte piéton' => 'PPV',
        'pieton' => 'PPV',
        'tourniquet' => 'TOU',
        'sas' => 'SAS',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    /**
     * Résultat de la persistance
     * 
     * @return array{
     *     inserted_contract: int,
     *     skipped_contract: int,
     *     inserted_offcontract: int,
     *     skipped_offcontract: int,
     *     generated_numbers: array<int, string>
     * }
     */
    public function persist(
        ExtractedFormData $formData,
        string $agencyCode,
        int $formId,
        int $dataId
    ): array {
        $stats = [
            'inserted_contract' => 0,
            'skipped_contract' => 0,
            'inserted_offcontract' => 0,
            'skipped_offcontract' => 0,
            'generated_numbers' => [], // kizeoIndex => numero
        ];

        if ($formData->idContact === null) {
            $this->kizeoLogger->warning('Impossible de persister sans idContact', [
                'form_id' => $formId,
                'data_id' => $dataId,
            ]);
            return $stats;
        }

        $tableName = $this->getTableName($agencyCode);

        // 1. Persister équipements au contrat
        foreach ($formData->contractEquipments as $equipment) {
            $result = $this->persistContractEquipment(
                $equipment,
                $formData,
                $tableName,
                $formId,
                $dataId
            );

            if ($result) {
                $stats['inserted_contract']++;
            } else {
                $stats['skipped_contract']++;
            }
        }

        // 2. Persister équipements hors contrat
        foreach ($formData->offContractEquipments as $equipment) {
            $result = $this->persistOffContractEquipment(
                $equipment,
                $formData,
                $tableName,
                $agencyCode,
                $formId,
                $dataId
            );

            if ($result !== null) {
                $stats['inserted_offcontract']++;
                $stats['generated_numbers'][$equipment->kizeoIndex] = $result;
            } else {
                $stats['skipped_offcontract']++;
            }
        }

        $this->kizeoLogger->info('Équipements persistés', [
            'form_id' => $formId,
            'data_id' => $dataId,
            'agency' => $agencyCode,
            ...$stats,
        ]);

        return $stats;
    }

    /**
     * Persiste un équipement au contrat
     * 
     * Clé de déduplication : numero_equipement + visite + annee
     * 
     * @return bool True si inséré, False si déjà existant
     */
    private function persistContractEquipment(
        ExtractedEquipment $equipment,
        ExtractedFormData $formData,
        string $tableName,
        int $formId,
        int $dataId
    ): bool {
        if (!$equipment->hasValidNumero()) {
            $this->kizeoLogger->debug('Équipement contrat ignoré (sans numéro)');
            return false;
        }

        $numero = strtoupper(trim($equipment->numeroEquipement));
        $visite = $equipment->getNormalizedVisite() ?? 'CE1';
        $annee = $formData->annee;

        // Vérifier si existe déjà
        if ($this->contractEquipmentExists($tableName, $numero, $visite, $annee, $formData->idContact)) {
            $this->kizeoLogger->debug('Équipement contrat déjà existant', [
                'numero' => $numero,
                'visite' => $visite,
                'annee' => $annee,
            ]);
            return false;
        }

        // Insérer
        $this->insertEquipment($tableName, [
            'id_contact' => $formData->idContact,
            'numero_equipement' => $numero,
            'libelle_equipement' => $equipment->libelleEquipement,
            'visite' => $visite,
            'annee' => $annee,
            'date_derniere_visite' => $formData->dateVisite?->format('Y-m-d'),
            'repere_site_client' => $equipment->repereSiteClient,
            'mise_en_service' => $equipment->miseEnService,
            'numero_serie' => $equipment->numeroSerie,
            'marque' => $equipment->marque,
            'mode_fonctionnement' => $equipment->modeFonctionnement,
            'hauteur' => $equipment->hauteur,
            'largeur' => $equipment->largeur,
            'longueur' => $equipment->longueur,
            'etat_equipement' => $equipment->etatEquipement,
            'anomalies' => $equipment->anomalies,
            'trigramme_tech' => $formData->trigramme,
            'is_hors_contrat' => 0,  // ← CORRIGÉ: équipement AU contrat
            'kizeo_form_id' => $formId,
            'kizeo_data_id' => $dataId,
            'date_enregistrement' => (new \DateTime())->format('Y-m-d H:i:s'),  // ← CORRIGÉ
        ]);

        $this->kizeoLogger->debug('Équipement contrat inséré', [
            'numero' => $numero,
            'visite' => $visite,
        ]);

        return true;
    }

    /**
     * Vérifie si un équipement au contrat existe déjà
     */
    private function contractEquipmentExists(
        string $tableName,
        string $numero,
        string $visite,
        string $annee,
        int $idContact
    ): bool {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE id_contact = ? AND numero_equipement = ? AND visite = ? AND annee = ?',
            $tableName
        );
        return (int) $this->connection->fetchOne($sql, [$idContact, $numero, $visite, $annee]) > 0;
    }

    /**
     * Persiste un équipement hors contrat
     * 
     * Clé de déduplication : kizeo_form_id + kizeo_data_id + kizeo_index
     * 
     * @return string|null Numéro généré si inséré, null si déjà existant
     */
    private function persistOffContractEquipment(
        ExtractedEquipment $equipment,
        ExtractedFormData $formData,
        string $tableName,
        string $agencyCode,
        int $formId,
        int $dataId
    ): ?string {
        $kizeoIndex = $equipment->kizeoIndex ?? 0;

        // Vérifier si existe déjà
        if ($this->offContractEquipmentExists($tableName, $formId, $dataId, $kizeoIndex)) {
            $this->kizeoLogger->debug('Équipement hors contrat déjà existant', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'kizeo_index' => $kizeoIndex,
            ]);
            return null;
        }

        // Générer le numéro
        $numero = $this->generateOffContractNumber(
            $tableName,
            $equipment->getTypePrefix(),
            $formData->idContact
        );

        // Insérer
        $this->insertEquipment($tableName, [
            'id_contact' => $formData->idContact,
            'numero_equipement' => $numero,
            'visite' => 'CE1',  // Défaut pour hors contrat
            'annee' => $formData->annee,
            'date_derniere_visite' => $formData->dateVisite?->format('Y-m-d'),
            'marque' => $equipment->marque,
            'etat_equipement' => $equipment->etatEquipement,
            'anomalies' => $equipment->anomalies,
            'trigramme_tech' => $formData->trigramme,  // ← CORRIGÉ
            'is_hors_contrat' => 1,  // ← CORRIGÉ: équipement HORS contrat
            'kizeo_form_id' => $formId,
            'kizeo_data_id' => $dataId,
            'kizeo_index' => $kizeoIndex,
            'date_enregistrement' => (new \DateTime())->format('Y-m-d H:i:s'),  // ← CORRIGÉ
        ]);

        $this->kizeoLogger->debug('Équipement hors contrat inséré', [
            'numero' => $numero,
            'type' => $equipment->typeEquipement,
        ]);

        return $numero;
    }

    /**
     * Vérifie si un équipement hors contrat existe déjà
     */
    private function offContractEquipmentExists(
        string $tableName,
        int $formId,
        int $dataId,
        int $kizeoIndex
    ): bool {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE kizeo_form_id = ? AND kizeo_data_id = ? AND kizeo_index = ?',
            $tableName
        );

        return (int) $this->connection->fetchOne($sql, [$formId, $dataId, $kizeoIndex]) > 0;
    }

    /**
     * Génère le prochain numéro pour un équipement hors contrat
     * 
     * Logique : Trouver le dernier numéro du même préfixe pour ce client, incrémenter
     * Exemple : SEC03 → SEC04
     */
    private function generateOffContractNumber(
        string $tableName,
        string $prefix,
        int $idContact
    ): string {
        // Trouver le dernier numéro avec ce préfixe pour ce client
        $sql = sprintf(
            "SELECT numero_equipement FROM %s 
             WHERE id_contact = ? 
             AND numero_equipement LIKE ? 
             ORDER BY numero_equipement DESC 
             LIMIT 1",
            $tableName
        );

        $lastNumber = $this->connection->fetchOne($sql, [$idContact, $prefix . '%']);

        if ($lastNumber) {
            // Extraire le numéro (SEC03 → 3)
            $num = (int) substr($lastNumber, strlen($prefix));
            $nextNum = $num + 1;
        } else {
            $nextNum = 1;
        }

        // Formater avec zéros (SEC01, SEC02, ..., SEC99)
        return sprintf('%s%02d', $prefix, $nextNum);
    }

    /**
     * Insère un équipement en base
     */
    private function insertEquipment(string $tableName, array $data): void
    {
        // Filtrer les valeurs null pour éviter les erreurs SQL
        $filteredData = array_filter($data, fn($v) => $v !== null);

        $columns = implode(', ', array_keys($filteredData));
        $placeholders = implode(', ', array_fill(0, count($filteredData), '?'));

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $placeholders);

        $this->connection->executeStatement($sql, array_values($filteredData));
    }

    /**
     * Retourne le nom de la table équipements pour une agence
     */
    private function getTableName(string $agencyCode): string
    {
        // Format : equipement_s10, equipement_s40, etc.
        return 'equipement_' . strtolower($agencyCode);
    }

    /**
     * Retourne le préfixe pour un type d'équipement
     */
    public static function getTypePrefix(string $typeEquipement): string
    {
        $type = strtolower(trim($typeEquipement));

        foreach (self::TYPE_PREFIXES as $keyword => $prefix) {
            if (str_contains($type, $keyword)) {
                return $prefix;
            }
        }

        return 'EQU';
    }
}