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
            'updated_contract' => 0,
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
            $status = $this->persistContractEquipment(
                $equipment,
                $formData,
                $tableName,
                $formId,
                $dataId
            );

            match ($status) {
                'inserted' => $stats['inserted_contract']++,
                'updated'  => $stats['updated_contract']++,
                default    => $stats['skipped_contract']++,
            };
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
     * Persiste un équipement au contrat (UPSERT).
     *
     * Clé d'identité : id_contact + numero_equipement + visite + annee
     *
     * CORRECTION 11/06/2026 — UPSERT au lieu de INSERT-only :
     *   Avant, si la ligne existait déjà (ex. équipement pré-créé manuellement
     *   via « Gestion de parc » pour qu'il apparaisse dans la liste Kizeo du
     *   technicien, ou généré en masse), l'import sautait l'équipement et JETAIT
     *   toutes les données du CR (marque, n° série, mise en service, statut,
     *   anomalies…). Résultat : équipements vides en BDD, et techniciens obligés
     *   de re-saisir les mesures d'une visite à l'autre.
     *
     *   Désormais : si le CR est plus récent que la ligne existante (ou si la
     *   ligne n'a pas de date de visite — cas typique d'une saisie parc), on
     *   MET À JOUR la ligne avec les données du CR. La fusion est défensive
     *   (cf. preferNew) : une valeur existante n'est JAMAIS écrasée par une
     *   valeur vide/NULL du CR — on ne perd jamais de donnée.
     *
     * @return string 'inserted' | 'updated' | 'skipped'
     */
    private function persistContractEquipment(
        ExtractedEquipment $equipment,
        ExtractedFormData $formData,
        string $tableName,
        int $formId,
        int $dataId
    ): string {
        if (!$equipment->hasValidNumero()) {
            $this->kizeoLogger->debug('Équipement contrat ignoré (sans numéro)');
            return 'skipped';
        }

        $numero = strtoupper(trim($equipment->numeroEquipement));
        $visite = $equipment->getNormalizedVisite() ?? 'CE1';
        $annee = $formData->annee;

        $existing = $this->findExistingContractEquipment($tableName, $numero, $visite, $annee, $formData->idContact);

        // ── Cas 1 : aucune ligne active → INSERT ──
        if ($existing === null) {
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

            return 'inserted';
        }

        // ── Cas 2 : ligne existante mais CR plus ancien (ou sans date) → SKIP ──
        // On ne régresse jamais une visite déjà enregistrée par un CR antérieur.
        $newDate = $formData->dateVisite?->format('Y-m-d');
        if (!$this->crIsNewer($existing['date_derniere_visite'] ?? null, $newDate)) {
            $this->kizeoLogger->debug('Équipement contrat déjà à jour (CR non plus récent)', [
                'numero' => $numero,
                'visite' => $visite,
                'annee' => $annee,
                'date_existante' => $existing['date_derniere_visite'] ?? null,
                'date_cr' => $newDate,
            ]);
            return 'skipped';
        }

        // ── Cas 3 : CR plus récent → UPDATE (fusion défensive) ──
        $this->updateContractEquipment($tableName, (int) $existing['id'], $existing, $equipment, $formData, $formId, $dataId);

        $this->kizeoLogger->info('Équipement contrat mis à jour (UPSERT)', [
            'numero' => $numero,
            'visite' => $visite,
            'annee' => $annee,
            'id' => $existing['id'],
            'date_existante' => $existing['date_derniere_visite'] ?? null,
            'date_cr' => $newDate,
            'statut' => $equipment->statutEquipement,
        ]);

        return 'updated';
    }

    /**
     * Récupère la ligne active (is_archive = 0) d'un équipement au contrat
     * pour la clé id_contact + numero + visite + annee, ou null si absente.
     *
     * On cible la ligne ACTIVE la plus récente : une ligne archivée ne bloque
     * pas la ré-apparition d'un équipement re-signalé sur le terrain.
     *
     * @return array<string, mixed>|null
     */
    private function findExistingContractEquipment(
        string $tableName,
        string $numero,
        string $visite,
        string $annee,
        int $idContact
    ): ?array {
        $sql = sprintf(
            'SELECT * FROM %s
             WHERE id_contact = ? AND numero_equipement = ? AND visite = ? AND annee = ? AND is_archive = 0
             ORDER BY id DESC LIMIT 1',
            $tableName
        );

        $row = $this->connection->fetchAssociative($sql, [$idContact, $numero, $visite, $annee]);

        return $row === false ? null : $row;
    }

    /**
     * Détermine si le CR (date $newDate) doit mettre à jour la ligne existante
     * (date $existingDate).
     *
     * Règles :
     *  - CR sans date  → on ne touche pas (impossible de garantir la fraîcheur).
     *  - Ligne sans date (saisie parc / génération en masse) → toujours mettre à jour.
     *  - Sinon → mettre à jour uniquement si le CR est STRICTEMENT plus récent
     *    (idempotent : ré-importer le même CR ne déclenche pas d'update).
     *
     * Les dates sont au format ISO 'Y-m-d' → comparaison lexicographique = chronologique.
     */
    private function crIsNewer(?string $existingDate, ?string $newDate): bool
    {
        if ($newDate === null || $newDate === '') {
            return false;
        }
        if ($existingDate === null || $existingDate === '') {
            return true;
        }
        return $newDate > $existingDate;
    }

    /**
     * Met à jour une ligne d'équipement au contrat avec les données du CR.
     *
     * Fusion défensive (preferNew) : pour les champs « fiche équipement », la
     * valeur existante est conservée si le CR renvoie vide/NULL — on n'efface
     * jamais une donnée déjà présente. Les champs de provenance/visite
     * (date_derniere_visite, trigramme, kizeo_*) sont positionnés sur le CR
     * courant puisqu'il est, par construction, le plus récent.
     *
     * @param array<string, mixed> $existing Ligne BDD actuelle
     */
    private function updateContractEquipment(
        string $tableName,
        int $id,
        array $existing,
        ExtractedEquipment $equipment,
        ExtractedFormData $formData,
        int $formId,
        int $dataId
    ): void {
        $data = [
            // Champs fiche équipement : fusion défensive (ne jamais écraser par du vide)
            'libelle_equipement'  => $this->preferNew($equipment->libelleEquipement, $existing['libelle_equipement'] ?? null),
            'repere_site_client'  => $this->preferNew($equipment->repereSiteClient, $existing['repere_site_client'] ?? null),
            'mise_en_service'     => $this->preferNew($equipment->miseEnService, $existing['mise_en_service'] ?? null),
            'numero_serie'        => $this->preferNew($equipment->numeroSerie, $existing['numero_serie'] ?? null),
            'marque'              => $this->preferNew($equipment->marque, $existing['marque'] ?? null),
            'mode_fonctionnement' => $this->preferNew($equipment->modeFonctionnement, $existing['mode_fonctionnement'] ?? null),
            'hauteur'             => $this->preferNew($equipment->hauteur, $existing['hauteur'] ?? null),
            'largeur'             => $this->preferNew($equipment->largeur, $existing['largeur'] ?? null),
            'longueur'            => $this->preferNew($equipment->longueur, $existing['longueur'] ?? null),
            'statut_equipement'   => $this->preferNew($equipment->statutEquipement, $existing['statut_equipement'] ?? null),
            'etat_equipement'     => $this->preferNew($equipment->etatEquipement, $existing['etat_equipement'] ?? null),
            'anomalies'           => $this->preferNew($equipment->anomalies, $existing['anomalies'] ?? null),
            'observations'        => $this->preferNew($equipment->observations, $existing['observations'] ?? null),
            // Champs de la visite courante : on prend le CR (le plus récent)
            'date_derniere_visite' => $formData->dateVisite?->format('Y-m-d') ?? ($existing['date_derniere_visite'] ?? null),
            'trigramme_tech'       => $this->preferNew($formData->trigramme, $existing['trigramme_tech'] ?? null),
            'kizeo_form_id'        => $formId,
            'kizeo_data_id'        => $dataId,
            'date_modification'    => (new \DateTime())->format('Y-m-d H:i:s'),
        ];

        $setParts = [];
        $params = [];
        foreach ($data as $column => $value) {
            $setParts[] = sprintf('%s = ?', $column);
            $params[] = $value;
        }
        $params[] = $id;

        $sql = sprintf('UPDATE %s SET %s WHERE id = ?', $tableName, implode(', ', $setParts));

        $this->connection->executeStatement($sql, $params);
    }

    /**
     * Retourne la valeur du CR si elle est renseignée (non vide après trim),
     * sinon la valeur existante — garantit qu'on n'efface jamais une donnée.
     */
    private function preferNew(?string $new, mixed $existing): mixed
    {
        if ($new !== null && trim($new) !== '') {
            return trim($new);
        }
        return $existing;
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