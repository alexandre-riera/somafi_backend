<?php

namespace App\Service;

use App\DTO\EquipementBulkDTO;
use Psr\Log\LoggerInterface;

/**
 * Service de génération d'équipements en masse — V2 CORRIGÉE
 *
 * CORRECTIONS V2 :
 *   - Logique CEA/CE1 : si le contrat a 2+ visites/an, un équipement
 *     à 1 visite passe en CE1 (pas CEA). CEA uniquement si le contrat
 *     entier est à 1 visite/an.
 *   - Mapping TYPE_PREFIXES complet et cohérent avec OffContractNumberGenerator
 *   - generate() accepte maintenant $nombreVisitesContrat
 */
class EquipementBulkGeneratorService
{
    /**
     * Mapping nombre de visites → codes visite.
     */
    private const VISIT_CODES = [
        1 => ['CEA'],
        2 => ['CE1', 'CE2'],
        3 => ['CE1', 'CE2', 'CE3'],
        4 => ['CE1', 'CE2', 'CE3', 'CE4'],
    ];

    /**
     * Mapping préfixe → libellé pour le formulaire (liste déroulante).
     * Cohérent avec OffContractNumberGenerator + KizeoFormProcessor.
     */
    public const TYPE_PREFIXES = [
        // Portes
        'SEC' => 'Porte sectionnelle',
        'RAP' => 'Porte rapide',
        'CFE' => 'Porte coupe-feu',
        'BAS' => 'Porte basculante',
        'COU' => 'Porte coulissante',
        'PIV' => 'Porte pivotante',
        'PPV' => 'Porte piétonne',
        // Portails
        'PAU' => 'Portail automatique',
        'PMA' => 'Portail manuel',
        'PMO' => 'Portail motorisé',
        // Barrières
        'BLE' => 'Barrière levante',
        // Quais
        'NIV' => 'Niveleur de quai',
        // Autres
        'RID' => 'Rideau métallique',
        'SAS' => 'Sas',
        'MIP' => 'Mini-pont',
        'BUT' => 'Butoir',
        'TOU' => 'Tourniquet',
        'EQU' => 'Équipement (autre)',
    ];

    /**
     * Types considérés comme automatiques (min 2 visites/an réglementaire).
     */
    private const TYPES_AUTOMATIQUES = [
        'Porte automatique', 'Porte piétonne automatique', 'Porte rapide',
        'Porte coulissante', 'Barrière levante', 'Portail coulissant automatique',
        'Portail battant', 'Porte piéton automatique',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Point d'entrée principal.
     *
     * @param EquipementBulkDTO $dto
     * @param int $nombreVisitesContrat Nombre de visites/an du contrat (pour logique CEA/CE1)
     */
    public function generate(EquipementBulkDTO $dto, int $nombreVisitesContrat = 1): array
    {
        if ($dto->mode === EquipementBulkDTO::MODE_TYPE_QUANTITE) {
            $result = $this->generateFromMode1($dto, $nombreVisitesContrat);
        } else {
            $result = $this->generateFromMode2($dto, $nombreVisitesContrat);
        }

        $lines = $result['lines'];
        $warnings = $result['warnings'];

        $uniqueEquipements = [];
        foreach ($lines as $line) {
            $uniqueEquipements[$line['numero_equipement']] = true;
        }

        $stats = [
            'total_equipements' => count($uniqueEquipements),
            'total_lignes'      => count($lines),
        ];

        $this->logger->info('[BulkGenerator] Génération terminée.', [
            'mode'        => $dto->mode,
            'agency'      => $dto->agencyCode,
            'id_contact'  => $dto->idContact,
            'equipements' => $stats['total_equipements'],
            'lignes'      => $stats['total_lignes'],
        ]);

        return [
            'lines'    => $lines,
            'warnings' => $warnings,
            'stats'    => $stats,
        ];
    }

    // =========================================================================
    //  MODE 1 — Type + Quantité
    // =========================================================================

    private function generateFromMode1(EquipementBulkDTO $dto, int $nombreVisitesContrat): array
    {
        $lines = [];
        $warnings = [];

        foreach ($dto->lignesMode1 as $ligne) {
            $prefix    = $ligne['prefix'];
            $libelle   = $ligne['libelle'];
            $quantite  = $ligne['quantite'];
            $nbVisites = $ligne['nb_visites'];
            $marque    = $ligne['marque'];
            $modeFonct = $ligne['mode_fonctionnement'];
            $repere    = $ligne['repere_site_client'];
            $forceCea  = $ligne['force_cea'] ?? false;

            // Conformité réglementaire
            $warning = $this->checkConformiteReglementaire($libelle, $modeFonct, $nbVisites);
            if ($warning) {
                $warnings[] = $warning;
            }

            for ($i = 1; $i <= $quantite; $i++) {
                $numero = $prefix . str_pad((string) $i, 2, '0', STR_PAD_LEFT);

                $ventilated = $this->ventilateVisits(
                    numero: $numero,
                    libelle: $libelle,
                    nbVisites: $nbVisites,
                    annee: $dto->annee,
                    idContact: $dto->idContact,
                    marque: $marque,
                    modeFonctionnement: $modeFonct,
                    repereSiteClient: $repere,
                    nombreVisitesContrat: $nombreVisitesContrat,
                    forceCea: $forceCea,
                );

                array_push($lines, ...$ventilated);
            }
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    // =========================================================================
    //  MODE 2 — Plan de site
    // =========================================================================

    private function generateFromMode2(EquipementBulkDTO $dto, int $nombreVisitesContrat): array
    {
        $lines = [];
        $warnings = [];

        foreach ($dto->lignesMode2 as $ligne) {
            $plageText = $ligne['plage'];
            $libelle   = $ligne['libelle'];
            $nbVisites = $ligne['nb_visites'];
            $marque    = $ligne['marque'];
            $modeFonct = $ligne['mode_fonctionnement'];
            $repere    = $ligne['repere_site_client'];
            $forceCea  = $ligne['force_cea'] ?? false;

            $warning = $this->checkConformiteReglementaire($libelle, $modeFonct, $nbVisites);
            if ($warning) {
                $warnings[] = $warning;
            }

            $numeros = $this->parseRange($plageText);

            if (empty($numeros)) {
                $warnings[] = sprintf('Plage "%s" : format non reconnu, ignorée.', $plageText);
                continue;
            }

            foreach ($numeros as $numero) {
                $ventilated = $this->ventilateVisits(
                    numero: $numero,
                    libelle: $libelle,
                    nbVisites: $nbVisites,
                    annee: $dto->annee,
                    idContact: $dto->idContact,
                    marque: $marque,
                    modeFonctionnement: $modeFonct,
                    repereSiteClient: $repere,
                    nombreVisitesContrat: $nombreVisitesContrat,
                    forceCea: $forceCea,
                );

                array_push($lines, ...$ventilated);
            }
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    // =========================================================================
    //  PARSING PLAGES (Mode 2)
    // =========================================================================

    public function parseRange(string $text): array
    {
        $text = trim(strtoupper($text));

        if (empty($text)) {
            return [];
        }

        $normalized = str_replace(['→', '->', '⟶', '=>'], '-', $text);

        if (preg_match('/^([A-Z]+)(\d+)\s*-\s*([A-Z]+)(\d+)$/i', $normalized, $matches)) {
            $prefixStart = $matches[1];
            $numStart    = (int) $matches[2];
            $prefixEnd   = $matches[3];
            $numEnd      = (int) $matches[4];

            if ($prefixStart !== $prefixEnd) {
                $this->logger->warning('[BulkGenerator] Plage avec préfixes différents.', ['raw' => $text]);
                return [];
            }

            if ($numEnd < $numStart) {
                [$numStart, $numEnd] = [$numEnd, $numStart];
            }

            $padding = max(strlen($matches[2]), strlen($matches[4]));

            $result = [];
            for ($i = $numStart; $i <= $numEnd; $i++) {
                $result[] = $prefixStart . str_pad((string) $i, $padding, '0', STR_PAD_LEFT);
            }

            return $result;
        }

        if (preg_match('/^[A-Z]+\d+$/i', $normalized)) {
            return [$normalized];
        }

        $this->logger->warning('[BulkGenerator] Format de plage non reconnu.', ['raw' => $text]);
        return [];
    }

    // =========================================================================
    //  VENTILATION PAR VISITE — LOGIQUE CEA/CE1 CORRIGÉE
    // =========================================================================

    /**
     * Règle métier CEA vs CE1 :
     *   - CEA = Contrat Entretien Annuel → utilisé UNIQUEMENT quand le contrat
     *     entier est à 1 visite/an (tous les équipements ont 1 visite)
     *   - Si le contrat a 2+ visites/an et qu'un équipement spécifique n'a
     *     qu'1 visite, on utilise CE1 (pas CEA) car il est dans un contexte
     *     multi-visites
     *
     * @param int $nombreVisitesContrat Nombre de visites du contrat global
     */
    private function ventilateVisits(
        string $numero,
        string $libelle,
        int $nbVisites,
        string $annee,
        string $idContact,
        string $marque = '',
        string $modeFonctionnement = '',
        string $repereSiteClient = '',
        int $nombreVisitesContrat = 1,
        bool $forceCea = false,
    ): array {
        $nbVisites = max(1, min(4, $nbVisites));

        // LOGIQUE CEA/CE1 :
        // - Si force_cea ET contrat à 1 visite → CEA (OK)
        // - Si force_cea ET contrat à 2+ visites → CE1 (auto-correction, CEA interdit)
        // - Si pas force_cea ET 1 visite ET contrat 2+ visites → CE1
        // - Si pas force_cea ET 1 visite ET contrat 1 visite → CEA
        if ($nbVisites === 1) {
            if ($nombreVisitesContrat >= 2) {
                // Contrat multi-visites → toujours CE1 (même si l'utilisateur a demandé CEA)
                $visitCodes = ['CE1'];
            } elseif ($forceCea) {
                $visitCodes = ['CEA'];
            } else {
                // Contrat 1 visite, pas de force → CEA par défaut
                $visitCodes = ['CEA'];
            }
        } else {
            $visitCodes = self::VISIT_CODES[$nbVisites];
        }

        $rows = [];
        foreach ($visitCodes as $code) {
            $rows[] = [
                'id_contact'          => $idContact,
                'numero_equipement'   => $numero,
                'libelle_equipement'  => $libelle,
                'visite'              => $code,
                'annee'               => $annee,
                'marque'              => $marque,
                'mode_fonctionnement' => $modeFonctionnement,
                'repere_site_client'  => $repereSiteClient,
                'is_hors_contrat'     => 0,
                'is_archive'          => 0,
            ];
        }

        return $rows;
    }

    // =========================================================================
    //  CONFORMITÉ RÉGLEMENTAIRE
    // =========================================================================

    private function checkConformiteReglementaire(
        string $libelle,
        string $modeFonctionnement,
        int $nbVisites,
    ): ?string {
        if ($nbVisites >= 2) {
            return null;
        }

        $isAuto = false;
        $modeUpper = strtoupper($modeFonctionnement);
        if (str_contains($modeUpper, 'AUTO') || str_contains($modeUpper, 'MOTORIS')) {
            $isAuto = true;
        }

        if (!$isAuto) {
            $libelleUpper = strtoupper($libelle);
            foreach (self::TYPES_AUTOMATIQUES as $type) {
                if (str_contains($libelleUpper, strtoupper($type))) {
                    $isAuto = true;
                    break;
                }
            }
        }

        if ($isAuto) {
            return sprintf(
                '⚠️ CONFORMITÉ : "%s" est un équipement automatique — la réglementation impose un minimum de 2 visites/an (semestriel). Vous avez sélectionné %d visite(s).',
                $libelle,
                $nbVisites
            );
        }

        return null;
    }

    // =========================================================================
    //  UTILITAIRES PUBLICS
    // =========================================================================

    public static function getVisitCodes(int $nbVisites): array
    {
        return self::VISIT_CODES[max(1, min(4, $nbVisites))] ?? ['CEA'];
    }

    public static function getVisitLabel(int $nbVisites): string
    {
        return match ($nbVisites) {
            1 => 'Annuel (CEA)',
            2 => 'Semestriel (CE1 + CE2)',
            3 => 'Trimestriel hors été (CE1 + CE2 + CE3)',
            4 => 'Trimestriel complet (CE1 + CE2 + CE3 + CE4)',
            default => 'Inconnu',
        };
    }

    /**
     * Retourne le mapping préfixe → libellé pour le formulaire.
     * @return array<string, string>
     */
    public static function getTypePrefixes(): array
    {
        return self::TYPE_PREFIXES;
    }
}
