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
 * 
 * CORRECTION 06/02/2026 v2 — ALIGNEMENT HC:
 * - FIX #1: HC — mapping complet de TOUS les champs (repère, dimensions,
 *   mode fonctionnement, mise en service, n° série, libellé, statut, observations)
 * - FIX #2: HC — visite déduite depuis les équipements au contrat du même formulaire
 * - FIX #3: Contrat — ajout statut_equipement et observations
 * - FIX #4: TYPE_PREFIXES étendu (aligné avec ExtractedEquipment::getTypePrefix)
 * 
 * CORRECTION 07/02/2026 — FIX HC DÉDUPLIQUÉS:
 * - FIX #5: Quand un HC est dédupliqué (skip), récupérer son numéro existant
 *   en BDD et le placer dans generated_numbers pour que PhotoPersister
 *   et JobCreator puissent résoudre HC_0 → numéro réel
 */
class EquipmentPersister
{
    // Mapping des préfixes par type d'équipement
    // CORRECTION 06/02/2026: Étendu avec tous les types du KizeoFormProcessor
    private const TYPE_PREFIXES = [
        'porte sectionnelle' => 'SEC',
        'sectionnelle' => 'SEC',
        'porte rapide' => 'RAP',
        'rapide' => 'RAP',
        'rideau métallique' => 'RID',
        'rideau metallique' => 'RID',
        'rideau' => 'RID',
        'portail coulissant' => 'PAU',
        'portail battant' => 'PMO',
        'portail manuel' => 'PMA',
        'portail' => 'PAU',
        'barrière levante' => 'BLE',
        'barriere levante' => 'BLE',
        'barrière' => 'BLE',
        'barriere' => 'BLE',
        'niveleur de quai' => 'NIV',
        'niveleur' => 'NIV',
        'quai' => 'NIV',
        'porte coupe-feu' => 'CFE',
        'porte coupe feu' => 'CFE',
        'coupe-feu' => 'CFE',
        'coupe feu' => 'CFE',
        'porte automatique' => 'PAU',
        'automatique' => 'PAU',
        'porte piétonne' => 'PPV',
        'porte pieton' => 'PPV',
        'porte piéton' => 'PPV',
        'pieton' => 'PPV',
        'tourniquet' => 'TOU',
        'sas' => 'SAS',
        'bloc-roue' => 'BRO',
        'bloc roue' => 'BRO',
        'table elevatrice' => 'TEL',
        'butoir' => 'BUT',
        'buttoir' => 'BUT',
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
        // La visite HC = celle des équipements au contrat du même formulaire
        // (le technicien repère les HC pendant sa visite CE1/CE2/etc.)
        $visite = $this->resolveVisiteFromContractEquipments($formData);

        foreach ($formData->offContractEquipments as $equipment) {
            $kizeoIndex = $equipment->kizeoIndex ?? 0;

            $result = $this->persistOffContractEquipment(
                $equipment,
                $formData,
                $tableName,
                $agencyCode,
                $formId,
                $dataId,
                $visite
            );

            if ($result !== null) {
                // Nouveau HC inséré → numéro généré
                $stats['inserted_offcontract']++;
                $stats['generated_numbers'][$kizeoIndex] = $result;
            } else {
                // HC dédupliqué → récupérer le numéro existant en BDD
                // FIX #5 (07/02/2026): Sans ça, PhotoPersister et JobCreator
                // ne peuvent pas résoudre HC_0 → numéro réel → warning
                $stats['skipped_offcontract']++;
                $existingNumero = $this->getExistingOffContractNumero(
                    $tableName, $formId, $dataId, $kizeoIndex
                );
                if ($existingNumero !== null) {
                    $stats['generated_numbers'][$kizeoIndex] = $existingNumero;
                    $this->kizeoLogger->debug('HC dédupliqué: numéro existant récupéré pour photos/jobs', [
                        'form_id' => $formId,
                        'data_id' => $dataId,
                        'kizeo_index' => $kizeoIndex,
                        'numero' => $existingNumero,
                    ]);
                }
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

        // Insérer — FIX #3: ajout statut_equipement et observations
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
            'statut_equipement' => $equipment->statutEquipement,
            'etat_equipement' => $equipment->etatEquipement,
            'anomalies' => $equipment->anomalies,
            'observations' => $equipment->observations,
            'trigramme_tech' => $formData->trigramme,
            'is_hors_contrat' => 0,
            'kizeo_form_id' => $formId,
            'kizeo_data_id' => $dataId,
            'date_enregistrement' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $this->kizeoLogger->debug('Équipement contrat inséré', [
            'numero' => $numero,
            'visite' => $visite,
            'statut' => $equipment->statutEquipement,
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
     * CORRECTION 06/02/2026 v2:
     * - FIX #1: Mapping complet de TOUS les champs HC depuis le DTO enrichi
     * - FIX #2: visite = celle du formulaire (CE1, CE2...) déduite depuis les
     *   équipements au contrat (les HC sont repérés pendant la même visite)
     * 
     * @return string|null Numéro généré si inséré, null si déjà existant
     */
    private function persistOffContractEquipment(
        ExtractedEquipment $equipment,
        ExtractedFormData $formData,
        string $tableName,
        string $agencyCode,
        int $formId,
        int $dataId,
        string $visite
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

        // Insérer — FIX #1: TOUS les champs HC mappés
        $this->insertEquipment($tableName, [
            'id_contact' => $formData->idContact,
            'numero_equipement' => $numero,
            'libelle_equipement' => $equipment->libelleEquipement,
            'visite' => $visite,
            'annee' => $formData->annee,
            'date_derniere_visite' => $formData->dateVisite?->format('Y-m-d'),
            'repere_site_client' => $equipment->repereSiteClient,
            'mise_en_service' => $equipment->miseEnService,
            'numero_serie' => $equipment->numeroSerie,
            'marque' => $equipment->marque,
            'mode_fonctionnement' => $equipment->modeFonctionnement,
            'hauteur' => $equipment->hauteur,
            'largeur' => $equipment->largeur,
            'statut_equipement' => $equipment->statutEquipement,
            'etat_equipement' => $equipment->etatEquipement,
            'anomalies' => $equipment->anomalies,
            'observations' => $equipment->observations,
            'trigramme_tech' => $formData->trigramme,
            'is_hors_contrat' => 1,
            'is_archive' => 0,
            'kizeo_form_id' => $formId,
            'kizeo_data_id' => $dataId,
            'kizeo_index' => $kizeoIndex,
            'date_enregistrement' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        $this->kizeoLogger->debug('Équipement hors contrat inséré', [
            'numero' => $numero,
            'libelle' => $equipment->libelleEquipement,
            'type' => $equipment->typeEquipement,
            'type_prefix' => $equipment->getTypePrefix(),
            'statut' => $equipment->statutEquipement,
            'repere' => $equipment->repereSiteClient,
            'kizeo_index' => $kizeoIndex,
        ]);

        return $numero;
    }

    /**
     * Déduit la visite du formulaire depuis les équipements au contrat
     * 
     * Les équipements HC n'ont pas de visite propre : ils héritent de celle
     * du formulaire (CE1, CE2, CE3, CE4, CEA). On prend la visite du premier
     * équipement au contrat. Si aucun équipement au contrat, fallback sur CE1.
     */
    private function resolveVisiteFromContractEquipments(ExtractedFormData $formData): string
    {
        foreach ($formData->contractEquipments as $equipment) {
            $visite = $equipment->getNormalizedVisite();
            if ($visite !== null) {
                return $visite;
            }
        }

        // Fallback : pas d'équipement au contrat dans ce CR
        // (rare mais possible si le technicien n'a saisi que des HC)
        $this->kizeoLogger->notice('Aucun équipement au contrat pour déduire la visite HC, fallback CE1', [
            'form_id' => $formData->formId,
            'data_id' => $formData->dataId,
        ]);

        return 'CE1';
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
     * Récupère le numéro d'un équipement HC déjà existant en BDD
     * 
     * FIX #5 (07/02/2026): Quand un HC est dédupliqué, on a besoin de son
     * numéro pour que PhotoPersister et JobCreator puissent résoudre
     * les placeholders HC_0, HC_1... vers les vrais numéros (RID28, SEC05...).
     * 
     * @return string|null Le numéro existant ou null si introuvable
     */
    private function getExistingOffContractNumero(
        string $tableName,
        int $formId,
        int $dataId,
        int $kizeoIndex
    ): ?string {
        $sql = sprintf(
            'SELECT numero_equipement FROM %s WHERE kizeo_form_id = ? AND kizeo_data_id = ? AND kizeo_index = ? LIMIT 1',
            $tableName
        );

        $result = $this->connection->fetchOne($sql, [$formId, $dataId, $kizeoIndex]);

        return $result !== false ? (string) $result : null;
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
        $type = mb_strtolower(trim($typeEquipement));

        foreach (self::TYPE_PREFIXES as $keyword => $prefix) {
            if (str_contains($type, $keyword)) {
                return $prefix;
            }
        }

        return 'EQU';
    }
}