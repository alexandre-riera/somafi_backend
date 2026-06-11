<?php

declare(strict_types=1);

namespace App\Command\Kizeo;

use App\Service\Kizeo\KizeoListBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Détection LECTURE SEULE des repères site client pollués en BDD.
 *
 * Contexte (bug de mapping liste Kizeo) : tant que la liste poussait une
 * dimension en colonne 8, le formulaire pré-remplissait
 * « localisation_site_client » avec cette dimension, et le CR la réenregistrait
 * dans equipement.repere_site_client. Résultat : des repères qui valent en
 * réalité une dimension (hauteur/largeur) de l'équipement.
 *
 * Cette commande N'ÉCRIT RIEN (sauf le fichier CSV si --csv est fourni).
 *
 * Règle métier de tri (validée avec Alex) :
 *   - repère numérique de 1 à 600  → VRAI repère client (n° de quai…) → on garde
 *   - repère numérique > 600        → fausse donnée (dimension en mm)   → à NULL
 *   - repère non numérique          → vrai repère (« Départ »…)         → on garde
 *
 * Niveaux de corroboration affichés pour les pollués (>600) :
 *   - EXACT   : repère == hauteur ou == largeur actuelle (signature directe)
 *   - SUSPECT : >600 mais ne matche aucune dimension actuelle (à NULL quand même)
 *
 * Usage :
 *   php bin/console app:kizeo:detect-corrupted-repere --summary
 *   php bin/console app:kizeo:detect-corrupted-repere --agency=S170
 *   php bin/console app:kizeo:detect-corrupted-repere --csv=var/repere_pollues.csv
 */
#[AsCommand(
    name: 'app:kizeo:detect-corrupted-repere',
    description: 'LECTURE SEULE : détecte les repère_site_client pollués par une dimension (>600). Option --csv pour exporter.',
)]
class DetectCorruptedRepereCommand extends Command
{
    /** Borne haute d'un repère client plausible. Au-delà = dimension (mm). */
    private const REPERE_MAX_LEGIT = 600;

    /**
     * En-têtes CSV : ordre du contrat de liste Kizeo (11 colonnes) +
     * colonnes de diagnostic en fin.
     */
    private const CSV_HEADER = [
        'cle', 'libelle', 'mise_en_service', 'numero_serie', 'marque',
        'hauteur', 'largeur', 'repere_site_client', 'id_contact', 'id_societe', 'code_agence',
        'motif', 'date_visite',
    ];

    public function __construct(
        private readonly KizeoListBuilder $listBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence à cibler (ex: S170). Sinon, les 13 agences.')
            ->addOption('exact-only', null, InputOption::VALUE_NONE, 'N\'afficher/exporter que les EXACT (repère == hauteur/largeur).')
            ->addOption('summary', 's', InputOption::VALUE_NONE, 'Vue agrégée : compteurs + fenêtre de dates par agence, sans le détail ligne à ligne.')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Chemin du CSV à écrire avec les repères pollués (ordre des 11 colonnes de la liste).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $targetAgency = $input->getOption('agency');
        $exactOnly = (bool) $input->getOption('exact-only');
        $summary = (bool) $input->getOption('summary');
        $csvPath = $input->getOption('csv');

        if ($targetAgency !== null) {
            $targetAgency = strtoupper((string) $targetAgency);
            if (!$this->listBuilder->isValidAgencyCode($targetAgency)) {
                $io->error("Code agence invalide : {$targetAgency}");
                return Command::FAILURE;
            }
            $agencies = [$targetAgency];
        } else {
            $agencies = $this->listBuilder->getAgencyCodes();
        }

        $io->title('Détection repères pollués (LECTURE SEULE)');
        $io->writeln('<comment>Aucune écriture BDD/Kizeo. Règle : repère 1-600 = vrai, >600 = dimension à NULL.</comment>');
        if ($exactOnly) {
            $io->writeln('<comment>Filtre : EXACT uniquement (repère == hauteur/largeur).</comment>');
        }
        $io->newLine();

        // Compteurs globaux
        $totalExact = 0;
        $totalSuspect = 0;
        $totalActifs = 0;
        $totalLegit = 0; // repères 1-600 conservés
        $summaryRows = [];
        $globalMinDate = null;
        $globalMaxDate = null;
        $csvRows = [];

        // Compteurs « validation recouvrement » (parmi les pollués >600)
        $recHautVide = 0;
        $recHautRemplie = 0;
        $recEqHaut = 0;
        $recEqLarg = 0;

        foreach ($agencies as $agency) {
            $rows = $this->listBuilder->fetchActiveEquipments($agency);
            $totalActifs += count($rows);

            $agExact = 0;
            $agSuspect = 0;
            $agLegit = 0;
            $agMinDate = null;
            $agMaxDate = null;
            $findings = [];

            foreach ($rows as $row) {
                // Tally des repères clients légitimes (1-600), pour info
                $repNum = $this->numericRepere($row);
                if ($repNum !== null && $repNum >= 1 && $repNum <= self::REPERE_MAX_LEGIT) {
                    $agLegit++;
                    $totalLegit++;
                }

                $motif = $this->classifyRepere($row);
                if ($motif === null) {
                    continue;
                }
                if ($exactOnly && $motif['level'] !== 'EXACT') {
                    continue;
                }

                if ($motif['level'] === 'EXACT') {
                    $totalExact++;
                    $agExact++;
                    $date = $this->pollutionDate($row);
                    if ($date !== null) {
                        if ($agMinDate === null || $date < $agMinDate) {
                            $agMinDate = $date;
                        }
                        if ($agMaxDate === null || $date > $agMaxDate) {
                            $agMaxDate = $date;
                        }
                        if ($globalMinDate === null || $date < $globalMinDate) {
                            $globalMinDate = $date;
                        }
                        if ($globalMaxDate === null || $date > $globalMaxDate) {
                            $globalMaxDate = $date;
                        }
                    }
                } else {
                    $totalSuspect++;
                    $agSuspect++;
                }

                // Validation de la logique de recouvrement (parmi tous les pollués >600)
                $this->tallyRecovery($row, $recHautVide, $recHautRemplie, $recEqHaut, $recEqLarg);

                if ($csvPath !== null) {
                    $csvRows[] = $this->buildCsvRow($row, $agency, $motif);
                }

                if (!$summary) {
                    $findings[] = [
                        (string) ($row['id_contact'] ?? ''),
                        (string) ($row['numero_equipement'] ?? ''),
                        (string) ($row['visite'] ?? ''),
                        $this->fmt($row['repere_site_client'] ?? ''),
                        $this->fmt($row['hauteur'] ?? ''),
                        $this->fmt($row['largeur'] ?? ''),
                        $motif['level'] . ' — ' . $motif['reason'],
                    ];
                }
            }

            if ($agExact === 0 && $agSuspect === 0) {
                continue;
            }

            if ($summary) {
                $summaryRows[] = [
                    $agency,
                    (string) count($rows),
                    (string) $agLegit,
                    (string) $agExact,
                    $exactOnly ? '—' : (string) $agSuspect,
                    $this->fmtDate($agMinDate),
                    $this->fmtDate($agMaxDate),
                ];
                continue;
            }

            $io->section(sprintf('%s — %d repère(s) pollué(s) / %d actifs', $agency, count($findings), count($rows)));
            $io->table(
                ['id_contact', 'équipement', 'visite', 'repère', 'hauteur', 'largeur', 'motif'],
                $findings
            );
        }

        if ($summary && !empty($summaryRows)) {
            $io->section('Pollution par agence (fenêtre de dates = repères EXACT)');
            $io->table(
                ['Agence', 'Actifs', 'Repères 1-600', 'EXACT >600', 'SUSPECT >600', 'EXACT + ancien', 'EXACT + récent'],
                $summaryRows
            );
        }

        // ── Écriture CSV ──
        if ($csvPath !== null) {
            $written = $this->writeCsv($csvPath, $csvRows);
            if ($written) {
                $io->success(sprintf('CSV écrit : %s (%d ligne(s))', $csvPath, count($csvRows)));
            } else {
                $io->error(sprintf('Échec écriture CSV : %s', $csvPath));
            }
        }

        // ── Résumé global ──
        $io->section('Résumé global');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Agences scannées', (string) count($agencies)],
                ['Équipements actifs scannés', (string) $totalActifs],
                ['Repères clients légitimes (1-600)', (string) $totalLegit],
                ['POLLUÉS >600 — EXACT (== dimension)', (string) $totalExact],
                ['POLLUÉS >600 — SUSPECT (sans match)', $exactOnly ? 'masqué' : (string) $totalSuspect],
                ['Fenêtre EXACT — plus ancien', $this->fmtDate($globalMinDate)],
                ['Fenêtre EXACT — plus récent', $this->fmtDate($globalMaxDate)],
            ]
        );

        // ── Validation de la logique de recouvrement proposée ──
        $totalPollues = $recHautVide + $recHautRemplie;
        if (!$exactOnly && $totalPollues > 0) {
            $io->section('Validation recouvrement (parmi les pollués >600)');
            $io->writeln('<comment>Teste la prémisse « si hauteur vide » de l\'UPDATE de dé-corruption proposé.</comment>');
            $io->table(
                ['Métrique', 'Valeur'],
                [
                    ['Pollués >600 (total)', (string) $totalPollues],
                    ['  dont hauteur VIDE/non num.', (string) $recHautVide],
                    ['  dont hauteur REMPLIE (num.)', (string) $recHautRemplie],
                    ['repère == hauteur actuelle', (string) $recEqHaut],
                    ['repère == largeur actuelle', (string) $recEqLarg],
                ]
            );
        }

        if ($totalExact === 0 && ($exactOnly || $totalSuspect === 0)) {
            $io->success('Aucun repère pollué détecté.');
        } else {
            $io->warning(
                'Repères pollués détectés. Rien n\'a été modifié en base. '
                . 'Étape suivante (hors périmètre actuel) : décider du traitement, cron de PUT gelé pendant l\'opération.'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Valeur numérique d'un repère, ou null s'il n'est pas numérique.
     */
    private function numericRepere(array $row): ?float
    {
        $repere = trim((string) ($row['repere_site_client'] ?? ''));
        if ($repere === '' || !is_numeric($repere)) {
            return null;
        }

        return (float) $repere;
    }

    /**
     * Classe le repère d'une ligne : EXACT, SUSPECT, ou null (sain).
     *
     * Pollution = repère numérique > 600 (= dimension en mm). En-dessous, c'est
     * un vrai repère client (numéro de quai…) qu'on conserve.
     *
     * @return array{level: string, reason: string}|null
     */
    private function classifyRepere(array $row): ?array
    {
        $n = $this->numericRepere($row);
        if ($n === null || $n <= self::REPERE_MAX_LEGIT) {
            return null; // non numérique, vide, ou repère client légitime (1-600)
        }

        $repere = trim((string) ($row['repere_site_client'] ?? ''));
        $hauteur = trim((string) ($row['hauteur'] ?? ''));
        $largeur = trim((string) ($row['largeur'] ?? ''));

        if ($hauteur !== '' && $repere === $hauteur) {
            return ['level' => 'EXACT', 'reason' => '== hauteur'];
        }
        if ($largeur !== '' && $repere === $largeur) {
            return ['level' => 'EXACT', 'reason' => '== largeur'];
        }

        return ['level' => 'SUSPECT', 'reason' => 'dimension > 600'];
    }

    /**
     * Accumule les compteurs de validation du recouvrement pour une ligne polluée.
     */
    private function tallyRecovery(
        array $row,
        int &$hautVide,
        int &$hautRemplie,
        int &$eqHaut,
        int &$eqLarg,
    ): void {
        $repere = trim((string) ($row['repere_site_client'] ?? ''));
        $hauteur = trim((string) ($row['hauteur'] ?? ''));
        $largeur = trim((string) ($row['largeur'] ?? ''));

        if (is_numeric($hauteur) && (float) $hauteur > 0) {
            $hautRemplie++;
        } else {
            $hautVide++;
        }
        if ($hauteur !== '' && $repere === $hauteur) {
            $eqHaut++;
        }
        if ($largeur !== '' && $repere === $largeur) {
            $eqLarg++;
        }
    }

    /**
     * Construit une ligne CSV au format contrat de liste (11 colonnes) + diagnostic.
     *
     * @param array{level: string, reason: string} $motif
     * @return string[]
     */
    private function buildCsvRow(array $row, string $agency, array $motif): array
    {
        $cle = sprintf(
            '%s\\%s\\%s',
            trim((string) ($row['raison_sociale'] ?? '')),
            trim((string) ($row['visite'] ?? '')),
            trim((string) ($row['numero_equipement'] ?? ''))
        );

        return [
            $cle,
            (string) ($row['libelle_equipement'] ?? ''),
            (string) ($row['mise_en_service'] ?? ''),
            (string) ($row['numero_serie'] ?? ''),
            (string) ($row['marque'] ?? ''),
            (string) ($row['hauteur'] ?? ''),
            (string) ($row['largeur'] ?? ''),
            (string) ($row['repere_site_client'] ?? ''),
            (string) ($row['id_contact'] ?? ''),
            (string) ($row['id_societe'] ?? ''),
            $agency,
            $motif['level'] . ' — ' . $motif['reason'],
            $this->fmtDate($this->pollutionDate($row)),
        ];
    }

    /**
     * Écrit le CSV (séparateur ';', BOM UTF-8 pour Excel FR).
     *
     * @param string[][] $rows
     */
    private function writeCsv(string $path, array $rows): bool
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return false;
        }

        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($handle, self::CSV_HEADER, ';');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        fclose($handle);

        return true;
    }

    /**
     * Date à laquelle le repère a (vraisemblablement) été pollué : date de
     * dernière visite en priorité (la pollution se fait au moment du CR),
     * sinon date de modification de l'enregistrement.
     *
     * Renvoie une chaîne ISO ('Y-m-d H:i:s') triable lexicographiquement, ou null.
     */
    private function pollutionDate(array $row): ?string
    {
        foreach (['date_derniere_visite', 'date_modification'] as $col) {
            $value = trim((string) ($row[$col] ?? ''));
            if ($value !== '' && $value !== '0000-00-00 00:00:00') {
                return $value;
            }
        }

        return null;
    }

    private function fmt(mixed $v): string
    {
        $v = trim((string) $v);
        return $v === '' ? '∅' : $v;
    }

    private function fmtDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '∅';
        }

        // 'Y-m-d H:i:s' → 'Y-m-d'
        return substr($isoDate, 0, 10);
    }
}
