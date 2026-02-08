<?php

namespace App\Service\Kizeo;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Résout le type de photo (plaque, environnement, compte_rendu, etc.)
 * en croisant le media_name du job Kizeo avec les colonnes photo_* de la table photos.
 *
 * Logique :
 *   1. SELECT la ligne photos WHERE numero_equipement + id_contact + visite + annee
 *   2. Scanner chaque colonne photo_* pour trouver celle qui contient le media_name
 *   3. Retourner le nom de la colonne (sans préfixe "photo_") comme type
 *   4. Fallback "autre" si aucune correspondance
 */
class PhotoTypeResolver
{
    /**
     * Liste des colonnes photo_* de la table photos.
     * Maintenir en sync avec la structure BDD.
     */
    private const PHOTO_COLUMNS = [
        'photo_plaque',
        'photo_etiquette_somafi',
        'photo_environnement_equipement1',
        'photo_envirronement_eclairage',
        'photo_compte_rendu',
        'photo_2',
        'photo_choc',
        'photo_choc_montant',
        'photo_choc_tablier',
        'photo_choc_tablier_porte',
        'photo_deformation_plateau',
        'photo_deformation_plaque',
        'photo_deformation_structure',
        'photo_deformation_chassis',
        'photo_deformation_levre',
        'photo_fissure_cordon',
        'photo_panneau_intermediaire_i',
        'photo_panneau_bas_inter_ext',
        'photo_lame_basse__int_ext',
        'photo_lame_intermediaire_int_',
        'photo_coffret_de_commande',
        'photo_carte',
        'photo_rail',
        'photo_equerre_rail',
        'photo_fixation_coulisse',
        'photo_moteur',
        'photo_axe',
        'photo_serrure',
        'photo_serrure1',
        'photo_bache',
        'photo_joue',
        'photo_butoir',
        'photo_vantail',
        'photo_linteau',
        'photo_barriere',
        'photo_tourniquet',
        'photo_sas',
        'photo_feux',
        'photo_marquage_au_sol',
        'photo_marquage_au_sol_',
        'photo_marquage_au_sol_2',
        'photo_complementaire_equipement',
        'photo_feuille_prise_de_cote',
    ];

    /**
     * Cache en mémoire : clé = "id_contact|annee|visite|numero_equipement" → row photos
     * Évite les requêtes répétées pour le même équipement dans un chunk.
     */
    private array $cache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Résout le type de photo à partir du media_name Kizeo.
     *
     * @return string Le type de photo (ex: "plaque", "compte_rendu", "environnement_equipement1")
     *                ou "autre" si non trouvé
     */
    public function resolve(
        string $mediaName,
        string $equipmentNumero,
        int    $idContact,
        string $annee,
        string $visite,
    ): string {
        $row = $this->getPhotoRow($equipmentNumero, $idContact, $annee, $visite);

        if ($row === null) {
            $this->logger->debug('PhotoTypeResolver: aucune ligne photos trouvée', [
                'equipment_numero' => $equipmentNumero,
                'id_contact' => $idContact,
                'annee' => $annee,
                'visite' => $visite,
            ]);
            return 'autre';
        }

        foreach (self::PHOTO_COLUMNS as $column) {
            if (!empty($row[$column]) && $this->mediaNameMatches($mediaName, $row[$column])) {
                // Retourner le nom sans le préfixe "photo_"
                return substr($column, 6); // strlen('photo_') = 6
            }
        }

        $this->logger->debug('PhotoTypeResolver: media_name non trouvé dans les colonnes photo', [
            'media_name' => $mediaName,
            'equipment_numero' => $equipmentNumero,
            'id_contact' => $idContact,
        ]);

        return 'autre';
    }

    /**
     * Résolution batch pour un chunk de jobs.
     * Pré-charge toutes les lignes photos nécessaires en une seule requête,
     * puis résout chaque job.
     *
     * @param array $jobs Array of ['media_name', 'equipment_numero', 'id_contact', 'annee', 'visite']
     * @return array<int, string> job_id => photo_type
     */
    public function resolveBatch(array $jobs): array
    {
        // 1. Pré-charger les lignes photos pour tout le chunk
        $this->preloadForJobs($jobs);

        // 2. Résoudre chaque job
        $results = [];
        foreach ($jobs as $job) {
            $jobId = $job['id'];
            $results[$jobId] = $this->resolve(
                $job['media_name'],
                $job['equipment_numero'],
                (int) $job['id_contact'],
                $job['annee'],
                $job['visite'],
            );
        }

        return $results;
    }

    /**
     * Pré-charge les lignes photos pour un ensemble de jobs (batch).
     * Construit une requête avec OR pour récupérer toutes les lignes en une fois.
     */
    private function preloadForJobs(array $jobs): void
    {
        // Collecter les combinaisons uniques (id_contact, annee, visite, numero_equipement)
        $lookups = [];
        foreach ($jobs as $job) {
            $key = $this->cacheKey(
                $job['equipment_numero'],
                (int) $job['id_contact'],
                $job['annee'],
                $job['visite'],
            );
            if (!isset($this->cache[$key])) {
                $lookups[$key] = [
                    'numero_equipement' => $job['equipment_numero'],
                    'id_contact' => $job['id_contact'],
                    'annee' => $job['annee'],
                    'visite' => $job['visite'],
                ];
            }
        }

        if (empty($lookups)) {
            return; // Tout est déjà en cache
        }

        // Construire la requête batch
        $conditions = [];
        $params = [];
        $i = 0;
        foreach ($lookups as $lookup) {
            $conditions[] = sprintf(
                '(numero_equipement = :eq%d AND id_contact = :ic%d AND annee = :an%d AND visite = :vi%d)',
                $i, $i, $i, $i
            );
            $params["eq{$i}"] = $lookup['numero_equipement'];
            $params["ic{$i}"] = $lookup['id_contact'];
            $params["an{$i}"] = $lookup['annee'];
            $params["vi{$i}"] = $lookup['visite'];
            $i++;
        }

        $columns = implode(', ', array_merge(
            ['numero_equipement', 'id_contact', 'annee', 'visite'],
            self::PHOTO_COLUMNS
        ));

        $sql = sprintf(
            'SELECT %s FROM photos WHERE %s',
            $columns,
            implode(' OR ', $conditions)
        );

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);

            foreach ($rows as $row) {
                $key = $this->cacheKey(
                    $row['numero_equipement'],
                    (int) $row['id_contact'],
                    $row['annee'],
                    $row['visite'],
                );
                $this->cache[$key] = $row;
            }

            // Marquer les non-trouvés comme null en cache (éviter re-requêtes)
            foreach ($lookups as $key => $lookup) {
                if (!isset($this->cache[$key])) {
                    $this->cache[$key] = null;
                }
            }

            $this->logger->debug('PhotoTypeResolver: preload batch', [
                'lookups' => count($lookups),
                'found' => count($rows),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('PhotoTypeResolver: erreur preload batch', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Récupère la ligne photos pour un équipement donné (avec cache).
     */
    private function getPhotoRow(
        string $equipmentNumero,
        int    $idContact,
        string $annee,
        string $visite,
    ): ?array {
        $key = $this->cacheKey($equipmentNumero, $idContact, $annee, $visite);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Requête unitaire (fallback si pas de preload batch)
        $columns = implode(', ', array_merge(
            ['numero_equipement', 'id_contact', 'annee', 'visite'],
            self::PHOTO_COLUMNS
        ));

        $sql = sprintf(
            'SELECT %s FROM photos WHERE numero_equipement = :eq AND id_contact = :ic AND annee = :an AND visite = :vi LIMIT 1',
            $columns
        );

        try {
            $row = $this->connection->fetchAssociative($sql, [
                'eq' => $equipmentNumero,
                'ic' => $idContact,
                'an' => $annee,
                'vi' => $visite,
            ]);

            $this->cache[$key] = $row ?: null;
            return $this->cache[$key];
        } catch (\Exception $e) {
            $this->logger->error('PhotoTypeResolver: erreur requête', [
                'error' => $e->getMessage(),
                'equipment_numero' => $equipmentNumero,
            ]);
            return null;
        }
    }

    /**
     * Compare le media_name du job avec la valeur stockée dans une colonne photo.
     * Le media_name Kizeo peut être stocké avec ou sans extension dans la table photos.
     */
    private function mediaNameMatches(string $jobMediaName, string $columnValue): bool
    {
        // Comparaison exacte
        if ($jobMediaName === $columnValue) {
            return true;
        }

        // Le media_name dans kizeo_jobs peut être le nom complet,
        // et dans photos ça peut être stocké avec/sans extension
        // → comparer aussi sans extension
        $jobBase = pathinfo($jobMediaName, PATHINFO_FILENAME);
        $colBase = pathinfo($columnValue, PATHINFO_FILENAME);

        if ($jobBase === $colBase && $jobBase !== '') {
            return true;
        }

        // Dernier recours : le media_name du job est contenu dans la valeur colonne ou vice versa
        if (str_contains($columnValue, $jobMediaName) || str_contains($jobMediaName, $columnValue)) {
            return true;
        }

        return false;
    }

    /**
     * Vide le cache (à appeler entre les chunks pour libérer la mémoire).
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function cacheKey(string $equipmentNumero, int $idContact, string $annee, string $visite): string
    {
        return sprintf('%s|%d|%s|%s', $equipmentNumero, $idContact, $annee, $visite);
    }
}
