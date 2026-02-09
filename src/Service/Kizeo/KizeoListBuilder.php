<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service de construction de la liste d'équipements au format Kizeo External List
 * 
 * Responsabilités :
 * - Récupère les équipements actifs en BDD (dernière version par triplet unique)
 * - Récupère les clés des équipements complètement archivés
 * - Construit les items au format Kizeo API (clé:valeur, pipe-séparé, hiérarchie \\)
 * - Extrait les clés normalisées des items Kizeo existants pour le merge
 * 
 * Format Kizeo (11 segments séparés par pipe) :
 *   CLIENT:CLIENT\VISITE:VISITE\NUM:NUM|type:type|mes:mes|serie:serie|marque:marque|
 *   long:long|larg:larg|haut:haut|id_contact:id_contact|id_societe:id_societe|code:code
 * 
 * Clé unique de merge : id_contact\visite\numero_equipement
 * (id_contact est plus fiable que raison_sociale car les noms peuvent différer
 *  entre BDD historique et saisie manuelle Kizeo)
 * 
 * Créé le 08/02/2026 — Phase C : Synchro Kizeo External Lists
 */
class KizeoListBuilder
{
    /** @var string[] Codes des 13 agences */
    private const AGENCY_CODES = [
        'S10', 'S40', 'S50', 'S60', 'S70', 'S80', 'S100',
        'S120', 'S130', 'S140', 'S150', 'S160', 'S170',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    // ──────────────────────────────────────────────────────────────
    //  Récupération BDD
    // ──────────────────────────────────────────────────────────────

    /**
     * Récupère les équipements actifs (dernière version par triplet unique)
     * avec les infos contact pour le format Kizeo
     * 
     * @return array<int, array<string, mixed>> Lignes BDD avec raison_sociale + id_societe
     */
    public function fetchActiveEquipments(string $agencyCode): array
    {
        $tableSuffix = strtolower($agencyCode); // S10 → s10
        $equipTable = "equipement_{$tableSuffix}";
        $contactTable = "contact_{$tableSuffix}";

        // Sous-requête : dernier enregistrement actif par triplet unique
        $sql = <<<SQL
            SELECT 
                e.numero_equipement,
                e.visite,
                e.libelle_equipement,
                e.mise_en_service,
                e.numero_serie,
                e.marque,
                e.longueur,
                e.largeur,
                e.hauteur,
                e.id_contact,
                c.raison_sociale,
                c.id_societe
            FROM {$equipTable} e
            INNER JOIN {$contactTable} c ON e.id_contact = c.id_contact
            INNER JOIN (
                SELECT id_contact, visite, numero_equipement, MAX(id) AS max_id
                FROM {$equipTable}
                WHERE is_archive = 0
                GROUP BY id_contact, visite, numero_equipement
            ) latest ON e.id = latest.max_id
            WHERE e.is_archive = 0
            ORDER BY c.raison_sociale, e.visite, e.numero_equipement
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql);

            $this->kizeoLogger->debug('Équipements actifs récupérés', [
                'agency' => $agencyCode,
                'count' => count($rows),
            ]);

            return $rows;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération équipements actifs', [
                'agency' => $agencyCode,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Récupère les clés des équipements complètement archivés
     * (archivé ET aucune version active restante)
     * 
     * @return array<string, true> Map [clé_normalisée => true] pour lookup rapide
     */
    public function fetchArchivedKeys(string $agencyCode): array
    {
        $tableSuffix = strtolower($agencyCode);
        $equipTable = "equipement_{$tableSuffix}";

        $sql = <<<SQL
            SELECT DISTINCT 
                e.id_contact,
                e.visite,
                e.numero_equipement
            FROM {$equipTable} e
            WHERE e.is_archive = 1
            AND NOT EXISTS (
                SELECT 1 FROM {$equipTable} e2
                WHERE e2.id_contact = e.id_contact
                AND e2.visite = e.visite
                AND e2.numero_equipement = e.numero_equipement
                AND e2.is_archive = 0
            )
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql);
            $keys = [];

            foreach ($rows as $row) {
                $key = $this->buildMergeKey(
                    (string) ($row['id_contact'] ?? ''),
                    $row['visite'] ?? '',
                    $row['numero_equipement'] ?? ''
                );
                $keys[$key] = true;
            }

            $this->kizeoLogger->debug('Clés archivées récupérées', [
                'agency' => $agencyCode,
                'count' => count($keys),
            ]);

            return $keys;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération clés archivées', [
                'agency' => $agencyCode,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Construction format Kizeo
    // ──────────────────────────────────────────────────────────────

    /**
     * Construit un item Kizeo au format API (clé:valeur) à partir d'une ligne BDD
     * 
     * Format : 11 segments pipe-séparés, chaque segment en clé:valeur
     * Segment 1 : hiérarchique avec \\ (double backslash)
     */
    public function buildKizeoItem(array $row, string $agencyCode): string
    {
        $raisonSociale = trim($row['raison_sociale'] ?? '');
        $visite = trim($row['visite'] ?? '');
        $numEquip = trim($row['numero_equipement'] ?? '');

        // Segment 1 : clé hiérarchique Client\Visite\NumEquip (format clé:valeur avec \)
        // Note : dans le JSON API Kizeo, \ est encodé en \\ (échappement JSON standard)
        // Mais en PHP string, c'est un seul \ — json_encode s'occupe de l'échappement
        $segment1 = sprintf(
            '%s:%s\\%s:%s\\%s:%s',
            $raisonSociale, $raisonSociale,
            $visite, $visite,
            $numEquip, $numEquip
        );

        // Segments 2-11 : format clé:valeur simple
        $segments = [
            $segment1,
            $this->formatSegment($row['libelle_equipement'] ?? ''),
            $this->formatSegment($row['mise_en_service'] ?? ''),
            $this->formatSegment($row['numero_serie'] ?? ''),
            $this->formatSegment($row['marque'] ?? ''),
            $this->formatSegment($row['longueur'] ?? ''),
            $this->formatSegment($row['largeur'] ?? ''),
            $this->formatSegment($row['hauteur'] ?? ''),
            $this->formatSegment((string) ($row['id_contact'] ?? '')),
            $this->formatSegment($row['id_societe'] ?? ''),
            $this->formatSegment($agencyCode),
        ];

        return implode('|', $segments);
    }

    /**
     * Construit tous les items Kizeo pour une agence
     * 
     * @return array<string, string> Map [clé_merge => item_kizeo]
     */
    public function buildAllItems(string $agencyCode): array
    {
        $rows = $this->fetchActiveEquipments($agencyCode);
        $items = [];

        foreach ($rows as $row) {
            $key = $this->buildMergeKey(
                (string) ($row['id_contact'] ?? ''),
                $row['visite'] ?? '',
                $row['numero_equipement'] ?? ''
            );
            $items[$key] = $this->buildKizeoItem($row, $agencyCode);
        }

        return $items;
    }

    // ──────────────────────────────────────────────────────────────
    //  Gestion des clés de merge
    // ──────────────────────────────────────────────────────────────

    /**
     * Construit la clé de merge normalisée
     * Format : "ID_CONTACT\VISITE\NUM_EQUIP" (en majuscules, trimé)
     * 
     * On utilise id_contact (numérique) au lieu de raison_sociale car les noms
     * peuvent différer entre BDD (historique) et Kizeo (saisie manuelle).
     * L'id_contact est présent dans le segment 9 des items Kizeo.
     */
    public function buildMergeKey(string $idContact, string $visite, string $numEquip): string
    {
        return sprintf(
            '%s\\%s\\%s',
            trim($idContact),
            mb_strtoupper(trim($visite)),
            mb_strtoupper(trim($numEquip))
        );
    }

    /**
     * Extrait la clé de merge normalisée depuis un item Kizeo existant
     * 
     * L'item Kizeo a le format (11 segments pipe-séparés) :
     *   CLIENT:CLIENT\VISITE:VISITE\NUM:NUM|type|mes|serie|marque|long|larg|haut|id_contact:id_contact|id_societe|code
     * 
     * On extrait :
     * - visite + numero_equipement depuis le segment hiérarchique (segment 1)
     * - id_contact depuis le segment 9 (index 8 après split sur pipe)
     * 
     * Clé = "id_contact\visite\numero_equipement"
     */
    public function extractMergeKeyFromItem(string $kizeoItem): string
    {
        // Split tous les segments par pipe
        $segments = explode('|', $kizeoItem);

        // ── Segment hiérarchique (index 0) : CLIENT:CLIENT\VISITE:VISITE\NUM:NUM ──
        $hierarchicalPart = $segments[0] ?? '';
        $subSegments = explode('\\', $hierarchicalPart);

        // Extraire visite (sous-segment index 1) et numero_equipement (sous-segment index 2)
        $visite = '';
        $numEquip = '';

        if (isset($subSegments[1])) {
            $lastColon = strrpos($subSegments[1], ':');
            $visite = $lastColon !== false ? trim(substr($subSegments[1], $lastColon + 1)) : trim($subSegments[1]);
        }
        if (isset($subSegments[2])) {
            $lastColon = strrpos($subSegments[2], ':');
            $numEquip = $lastColon !== false ? trim(substr($subSegments[2], $lastColon + 1)) : trim($subSegments[2]);
        }

        // ── Segment 9 (index 8) : id_contact:id_contact ──
        $idContact = '';
        if (isset($segments[8])) {
            $lastColon = strrpos($segments[8], ':');
            $idContact = $lastColon !== false ? trim(substr($segments[8], $lastColon + 1)) : trim($segments[8]);
        }

        return $this->buildMergeKey($idContact, $visite, $numEquip);
    }

    // ──────────────────────────────────────────────────────────────
    //  Utilitaires
    // ──────────────────────────────────────────────────────────────

    /**
     * Formate un segment simple en clé:valeur
     * Si la valeur est vide, retourne ":" (cohérent avec le format Kizeo existant)
     */
    private function formatSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return ':';
        }
        return sprintf('%s:%s', $value, $value);
    }

    /**
     * Valide qu'un code agence est supporté
     */
    public function isValidAgencyCode(string $code): bool
    {
        return in_array(strtoupper($code), self::AGENCY_CODES, true);
    }

    /**
     * @return string[]
     */
    public function getAgencyCodes(): array
    {
        return self::AGENCY_CODES;
    }
}