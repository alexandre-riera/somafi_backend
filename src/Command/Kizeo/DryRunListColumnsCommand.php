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
 * Commande DRY-RUN de contrôle de l'ordre des colonnes de la liste Kizeo.
 *
 * N'écrit RIEN en base et ne pousse RIEN vers Kizeo. Pour chaque équipement
 * actif d'une agence, elle affiche l'item produit par
 * KizeoListBuilder::buildKizeoItem() décomposé segment par segment et
 * labellisé, afin de valider VISUELLEMENT le contrat de colonnes corrigé :
 *
 *   pos5 marque | pos6 HAUTEUR | pos7 LARGEUR | pos8 REPÈRE | pos9 id_contact …
 *
 * La pos 8 (repère) est référencée par le champ « localisation_site_client »
 * du formulaire Kizeo. La commande signale les lignes où le repère est vide
 * ou ressemble encore à une dimension (== hauteur/largeur), symptôme de
 * pollution résiduelle → à confirmer avec app:kizeo:detect-corrupted-repere.
 *
 * Usage :
 *   php bin/console app:kizeo:dry-run-columns --agency=S170 --limit=10
 *   php bin/console app:kizeo:dry-run-columns --agency=S170 --grep=NIV
 */
#[AsCommand(
    name: 'app:kizeo:dry-run-columns',
    description: 'DRY-RUN : aperçu labellisé de l\'ordre des colonnes Kizeo corrigé (Hauteur|Largeur|Repère). N\'écrit rien.',
)]
class DryRunListColumnsCommand extends Command
{
    /**
     * Libellés des 11 segments (index 0-based après split sur « | »).
     * Doit rester aligné sur KizeoListBuilder::buildKizeoItem().
     */
    private const SEGMENT_LABELS = [
        0 => 'clé (client\\visite\\num)',
        1 => 'libellé',
        2 => 'mise en service',
        3 => 'n° de série',
        4 => 'marque',
        5 => 'HAUTEUR',
        6 => 'LARGEUR',
        7 => 'REPÈRE site client',
        8 => 'id_contact',
        9 => 'id_societe',
        10 => 'code agence',
    ];

    public function __construct(
        private readonly KizeoListBuilder $listBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence (ex: S170)', 'S170')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre max d\'équipements affichés', '10')
            ->addOption('grep', 'g', InputOption::VALUE_REQUIRED, 'Filtre sur le numéro d\'équipement (ex: NIV, SEC)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $agency = strtoupper((string) $input->getOption('agency'));
        $limit = max(1, (int) $input->getOption('limit'));
        $grep = $input->getOption('grep') !== null ? strtoupper((string) $input->getOption('grep')) : null;

        if (!$this->listBuilder->isValidAgencyCode($agency)) {
            $io->error("Code agence invalide : {$agency}");
            return Command::FAILURE;
        }

        $io->title("DRY-RUN ordre colonnes liste Kizeo — {$agency}");
        $io->writeln('<comment>Aucune écriture BDD, aucun PUT Kizeo. Aperçu visuel de l\'item corrigé.</comment>');
        $io->writeln('Contrat attendu : <info>… | marque | Hauteur | Largeur | Repère | id_contact | …</info>');
        $io->newLine();

        $rows = $this->listBuilder->fetchActiveEquipments($agency);

        if (empty($rows)) {
            $io->warning("Aucun équipement actif pour {$agency}.");
            return Command::SUCCESS;
        }

        if ($grep !== null) {
            $rows = array_values(array_filter(
                $rows,
                fn(array $r) => str_contains(strtoupper((string) ($r['numero_equipement'] ?? '')), $grep)
            ));
        }

        $io->writeln(sprintf(
            'Équipements actifs : <info>%d</info>%s — affichage des <info>%d</info> premiers.',
            count($rows),
            $grep !== null ? " (filtre « {$grep} »)" : '',
            min($limit, count($rows))
        ));
        $io->newLine();

        $shown = 0;
        $suspects = 0;
        foreach ($rows as $row) {
            if ($shown >= $limit) {
                break;
            }
            $shown++;

            $numero = (string) ($row['numero_equipement'] ?? '?');
            $repereFlag = $this->repereSuspicion($row);
            if ($repereFlag !== null) {
                $suspects++;
            }

            $io->section(sprintf(
                '%s — %s (%s)',
                $numero,
                (string) ($row['libelle_equipement'] ?? ''),
                (string) ($row['visite'] ?? '')
            ));

            $item = $this->listBuilder->buildKizeoItem($row, $agency);
            $segments = explode('|', $item);

            foreach ($segments as $i => $segment) {
                $label = self::SEGMENT_LABELS[$i] ?? "seg{$i}";
                $color = in_array($i, [5, 6, 7], true) ? 'cyan' : 'default';
                $io->writeln(sprintf('  <fg=%s>pos%-2d %-22s</> %s', $color, $i + 1, $label, $segment));
            }

            if ($repereFlag !== null) {
                $io->writeln("  <fg=red>⚠ REPÈRE SUSPECT : {$repereFlag}</>");
            }
            $io->newLine();
        }

        if ($suspects > 0) {
            $io->warning(sprintf(
                '%d repère(s) suspect(s) sur les %d affichés (vide ou == dimension). '
                . 'Lance « app:kizeo:detect-corrupted-repere --agency=%s » pour le rapport complet.',
                $suspects,
                $shown,
                $agency
            ));
        }

        $io->success(sprintf(
            '%d équipement(s) affiché(s). Vérifie que pos8 = REPÈRE contient bien un repère (texte) et pas une dimension.',
            $shown
        ));

        return Command::SUCCESS;
    }

    /**
     * Détecte un repère résiduellement pollué : valeur NUMÉRIQUE strictement
     * égale à la hauteur ou la largeur de la même ligne (signature du bug
     * seg8=hauteur). Un repère vide ou textuel n'est pas considéré comme pollué.
     *
     * @return string|null Description du problème, ou null si le repère est sain
     */
    private function repereSuspicion(array $row): ?string
    {
        $repere = trim((string) ($row['repere_site_client'] ?? ''));

        // Une dimension est toujours numérique : un repère vide ou textuel
        // (« Départ », « A renseigner ») n'est pas une pollution par dimension.
        if ($repere === '' || !is_numeric($repere)) {
            return null;
        }

        $hauteur = trim((string) ($row['hauteur'] ?? ''));
        $largeur = trim((string) ($row['largeur'] ?? ''));

        if ($hauteur !== '' && $repere === $hauteur) {
            return "repère == hauteur ({$hauteur})";
        }
        if ($largeur !== '' && $repere === $largeur) {
            return "repère == largeur ({$largeur})";
        }

        return null;
    }
}
