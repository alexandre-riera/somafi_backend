<?php

namespace App\DTO;

/**
 * DTO pour la génération en masse d'équipements — V2
 *
 * CORRECTION V2 :
 *   - Gère la valeur "1_cea" pour forcer CEA vs "1" pour CE1
 *   - Le champ force_cea dans chaque ligne indique si l'utilisateur
 *     a explicitement choisi CEA
 */
class EquipementBulkDTO
{
    public const MODE_TYPE_QUANTITE = 'mode1';
    public const MODE_PLAN_SITE = 'mode2';

    public string $mode = self::MODE_TYPE_QUANTITE;
    public int $contactId;
    public string $idContact;
    public int $contratId;
    public string $annee;
    public string $agencyCode;

    /**
     * Mode 1 — Lignes type + quantité.
     * Chaque élément contient aussi 'force_cea' => bool
     * @var array<int, array<string, mixed>>
     */
    public array $lignesMode1 = [];

    /**
     * Mode 2 — Lignes plan de site.
     * @var array<int, array<string, mixed>>
     */
    public array $lignesMode2 = [];

    public static function fromRequest(array $data, string $agencyCode): self
    {
        $dto = new self();
        $dto->agencyCode = strtoupper($agencyCode);
        $dto->mode = $data['mode'] ?? self::MODE_TYPE_QUANTITE;
        $dto->contactId = (int) ($data['contact_id'] ?? 0);
        $dto->idContact = (string) ($data['id_contact'] ?? '');
        $dto->contratId = (int) ($data['contrat_id'] ?? 0);
        $dto->annee = (string) ($data['annee'] ?? date('Y'));

        if ($dto->mode === self::MODE_TYPE_QUANTITE && !empty($data['lignes_mode1'])) {
            foreach ($data['lignes_mode1'] as $ligne) {
                $nbVisitesRaw = $ligne['nb_visites'] ?? '2';
                // "1_cea" → 1 visite avec force CEA ; "1" → 1 visite CE1
                $forceCea = ($nbVisitesRaw === '1_cea');
                $nbVisites = ($nbVisitesRaw === '1_cea') ? 1 : max(1, min(4, (int) $nbVisitesRaw));

                $dto->lignesMode1[] = [
                    'prefix'              => strtoupper(trim($ligne['prefix'] ?? '')),
                    'libelle'             => trim($ligne['libelle'] ?? ''),
                    'quantite'            => max(1, (int) ($ligne['quantite'] ?? 1)),
                    'nb_visites'          => $nbVisites,
                    'force_cea'           => $forceCea,
                    'marque'              => trim($ligne['marque'] ?? ''),
                    'mode_fonctionnement' => trim($ligne['mode_fonctionnement'] ?? ''),
                    'repere_site_client'  => trim($ligne['repere_site_client'] ?? ''),
                ];
            }
        }

        if ($dto->mode === self::MODE_PLAN_SITE && !empty($data['lignes_mode2'])) {
            foreach ($data['lignes_mode2'] as $ligne) {
                $nbVisitesRaw = $ligne['nb_visites'] ?? '2';
                $forceCea = ($nbVisitesRaw === '1_cea');
                $nbVisites = ($nbVisitesRaw === '1_cea') ? 1 : max(1, min(4, (int) $nbVisitesRaw));

                $dto->lignesMode2[] = [
                    'plage'               => strtoupper(trim($ligne['plage'] ?? '')),
                    'libelle'             => trim($ligne['libelle'] ?? ''),
                    'nb_visites'          => $nbVisites,
                    'force_cea'           => $forceCea,
                    'marque'              => trim($ligne['marque'] ?? ''),
                    'mode_fonctionnement' => trim($ligne['mode_fonctionnement'] ?? ''),
                    'repere_site_client'  => trim($ligne['repere_site_client'] ?? ''),
                ];
            }
        }

        return $dto;
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->contactId <= 0) {
            $errors[] = 'Le contact_id est obligatoire.';
        }
        if (empty($this->idContact)) {
            $errors[] = 'Le id_contact est obligatoire.';
        }
        if ($this->contratId <= 0) {
            $errors[] = 'Le contrat_id est obligatoire.';
        }
        if (!in_array($this->mode, [self::MODE_TYPE_QUANTITE, self::MODE_PLAN_SITE], true)) {
            $errors[] = 'Le mode de génération est invalide.';
        }

        if ($this->mode === self::MODE_TYPE_QUANTITE) {
            if (empty($this->lignesMode1)) {
                $errors[] = 'Aucune ligne de type/quantité renseignée.';
            }
            foreach ($this->lignesMode1 as $i => $ligne) {
                if (empty($ligne['prefix'])) {
                    $errors[] = sprintf('Ligne %d : le type est obligatoire.', $i + 1);
                }
                if (empty($ligne['libelle'])) {
                    $errors[] = sprintf('Ligne %d : le libellé est obligatoire.', $i + 1);
                }
                if (($ligne['quantite'] ?? 0) < 1) {
                    $errors[] = sprintf('Ligne %d : la quantité doit être ≥ 1.', $i + 1);
                }
            }
        }

        if ($this->mode === self::MODE_PLAN_SITE) {
            if (empty($this->lignesMode2)) {
                $errors[] = 'Aucune ligne plan de site renseignée.';
            }
            foreach ($this->lignesMode2 as $i => $ligne) {
                if (empty($ligne['plage'])) {
                    $errors[] = sprintf('Ligne %d : la plage/identifiant est obligatoire.', $i + 1);
                }
                if (empty($ligne['libelle'])) {
                    $errors[] = sprintf('Ligne %d : le libellé est obligatoire.', $i + 1);
                }
            }
        }

        return $errors;
    }
}
