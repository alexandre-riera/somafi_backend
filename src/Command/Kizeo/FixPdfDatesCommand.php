<?php

namespace App\Command\Kizeo;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\DBAL\Connection;

/**
 * Renomme les PDF CR techniciens historiques avec la bonne date de visite.
 * 
 * Problème : les ~4000 PDF téléchargés en masse ont la date de téléchargement
 * (2026-02-07 / 2026-02-08) au lieu de la date de visite réelle.
 * 
 * Solution : extraire "Date : DD/MM/YYYY" du contenu du PDF (première page)
 * et renommer le fichier avec la bonne date.
 * 
 * Format actuel  : {CLIENT}-2026-02-07-{VISITE}-{DATA_ID}.pdf
 * Format corrigé : {CLIENT}-2025-08-28-{VISITE}-{DATA_ID}.pdf
 * 
 * Prérequis : composer require smalot/pdfparser
 * 
 * Usage :
 *   php bin/console app:kizeo:fix-pdf-dates --dry-run          # Prévisualisation
 *   php bin/console app:kizeo:fix-pdf-dates                    # Exécution réelle
 *   php bin/console app:kizeo:fix-pdf-dates --agency=S50       # Une seule agence
 *   php bin/console app:kizeo:fix-pdf-dates --limit=100        # Limiter le nombre
 */
#[AsCommand(
    name: 'app:kizeo:fix-pdf-dates',
    description: 'Renomme les PDF CR techniciens avec la vraie date de visite extraite du contenu',
)]
class FixPdfDatesCommand extends Command
{
    // Dates de téléchargement en masse à corriger
    private const WRONG_DATES = ['2026-02-06', '2026-02-07', '2026-02-08'];

    private SymfonyStyle $io;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prévisualisation sans renommage')
            ->addOption('agency', null, InputOption::VALUE_REQUIRED, 'Filtrer par agence (ex: S50)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre max de fichiers à traiter', 0)
            ->addOption('update-db', null, InputOption::VALUE_NONE, 'Mettre à jour local_path dans kizeo_jobs + date_derniere_visite dans equipement_sXX')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $agencyFilter = $input->getOption('agency');
        $limit = (int) $input->getOption('limit');
        $updateDb = $input->getOption('update-db');

        $this->io->title('Fix PDF Dates — Renommage CR techniciens');

        if ($dryRun) {
            $this->io->warning('MODE DRY-RUN — Aucune modification ne sera effectuée');
        }

        // Vérifier que smalot/pdfparser est installé
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->io->error('La librairie smalot/pdfparser est requise. Lance : composer require smalot/pdfparser');
            return Command::FAILURE;
        }

        $storageDir = $this->projectDir . '/storage/pdf';

        if (!is_dir($storageDir)) {
            $this->io->error("Dossier storage/pdf introuvable : $storageDir");
            return Command::FAILURE;
        }

        // Scanner les fichiers
        $files = $this->findPdfFilesToFix($storageDir, $agencyFilter, $limit);

        if (empty($files)) {
            $this->io->success('Aucun fichier à corriger trouvé.');
            return Command::SUCCESS;
        }

        $this->io->info(sprintf('Fichiers PDF à traiter : %d', count($files)));

        $parser = new \Smalot\PdfParser\Parser();

        $stats = [
            'renamed' => 0,
            'skipped_same_date' => 0,
            'skipped_no_date' => 0,
            'skipped_parse_error' => 0,
            'db_updated' => 0,
            'errors' => 0,
        ];

        $this->io->progressStart(count($files));

        foreach ($files as $filePath) {
            $this->io->progressAdvance();

            try {
                $result = $this->processFile($parser, $filePath, $dryRun, $updateDb);
                $stats[$result]++;
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->io->newLine();
                $this->io->warning(sprintf('Erreur sur %s : %s', basename($filePath), $e->getMessage()));
            }

            // Libérer la mémoire entre chaque PDF (certains font 20+ MB)
            gc_collect_cycles();
        }

        $this->io->progressFinish();

        // Bilan
        $this->io->newLine();
        $this->io->section('Bilan');
        $this->io->table(
            ['Métrique', 'Valeur'],
            [
                ['Fichiers renommés', $stats['renamed']],
                ['Date déjà correcte', $stats['skipped_same_date']],
                ['Date non trouvée dans le PDF', $stats['skipped_no_date']],
                ['Erreur de parsing PDF', $stats['skipped_parse_error']],
                ['MàJ BDD (kizeo_jobs + equipement)', $stats['db_updated']],
                ['Erreurs', $stats['errors']],
            ]
        );

        if ($dryRun) {
            $this->io->warning('Dry-run terminé. Relance sans --dry-run pour appliquer.');
        } else {
            $this->io->success(sprintf('Terminé ! %d fichiers renommés.', $stats['renamed']));
        }

        return Command::SUCCESS;
    }

    /**
     * Scanne storage/pdf/ et retourne les fichiers dont le nom contient une date erronée
     */
    private function findPdfFilesToFix(string $storageDir, ?string $agencyFilter, int $limit): array
    {
        $files = [];

        // Pattern : storage/pdf/{agency}/{id_contact}/{annee}/{visite}/*.pdf
        $agencies = scandir($storageDir);

        foreach ($agencies as $agency) {
            if ($agency === '.' || $agency === '..') continue;
            if ($agencyFilter && strtoupper($agencyFilter) !== strtoupper($agency)) continue;

            $agencyDir = $storageDir . '/' . $agency;
            if (!is_dir($agencyDir)) continue;

            $contacts = scandir($agencyDir);
            foreach ($contacts as $contact) {
                if ($contact === '.' || $contact === '..') continue;

                $contactDir = $agencyDir . '/' . $contact;
                if (!is_dir($contactDir)) continue;

                $years = scandir($contactDir);
                foreach ($years as $year) {
                    if ($year === '.' || $year === '..') continue;

                    $yearDir = $contactDir . '/' . $year;
                    if (!is_dir($yearDir)) continue;

                    $visits = scandir($yearDir);
                    foreach ($visits as $visit) {
                        if ($visit === '.' || $visit === '..') continue;

                        $visitDir = $yearDir . '/' . $visit;
                        if (!is_dir($visitDir)) continue;

                        $pdfs = glob($visitDir . '/*.pdf');
                        if ($pdfs === false) continue;

                        foreach ($pdfs as $pdf) {
                            $filename = basename($pdf);

                            // Vérifier si le nom contient une des dates erronées
                            foreach (self::WRONG_DATES as $wrongDate) {
                                if (str_contains($filename, $wrongDate)) {
                                    $files[] = $pdf;
                                    break;
                                }
                            }

                            if ($limit > 0 && count($files) >= $limit) {
                                return $files;
                            }
                        }
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Traite un fichier PDF : extrait la date de visite et renomme si nécessaire
     * 
     * @return string La clé de stats correspondante
     */
    private function processFile(\Smalot\PdfParser\Parser $parser, string $filePath, bool $dryRun, bool $updateDb): string
    {
        $filename = basename($filePath);
        $dir = dirname($filePath);

        // Extraire la date du contenu du PDF
        try {
            $pdf = $parser->parseFile($filePath);

            // Extraire le texte des premières pages seulement (optimisation mémoire)
            $pages = $pdf->getPages();
            $text = '';
            $maxPages = min(2, count($pages));
            for ($i = 0; $i < $maxPages; $i++) {
                $text .= $pages[$i]->getText();
            }

            // Libérer la mémoire du PDF parsé
            unset($pdf, $pages);
        } catch (\Exception $e) {
            if ($this->io->isVerbose()) {
                $this->io->newLine();
                $this->io->comment("  Parse error: {$filename} — {$e->getMessage()}");
            }
            return 'skipped_parse_error';
        }

        // Chercher "Date : DD/MM/YYYY" (pas "Date de réponse")
        // Pattern : "Date" suivi de " : " ou " :" puis une date au format JJ/MM/AAAA
        // On exclut "Date de réponse" et "Date effective" etc.
        $realDate = $this->extractVisitDate($text);

        if (!$realDate) {
            if ($this->io->isVerbose()) {
                $this->io->newLine();
                $this->io->comment("  Date non trouvée dans : {$filename}");
            }
            return 'skipped_no_date';
        }

        // Extraire la date actuelle du nom de fichier
        // Format : {CLIENT}-{YYYY-MM-DD}-{VISITE}-{DATA_ID}.pdf
        $currentDate = $this->extractDateFromFilename($filename);

        if (!$currentDate) {
            if ($this->io->isVerbose()) {
                $this->io->newLine();
                $this->io->comment("  Format nom de fichier non reconnu : {$filename}");
            }
            return 'skipped_no_date';
        }

        // Comparer
        if ($currentDate === $realDate) {
            return 'skipped_same_date';
        }

        // Construire le nouveau nom
        $newFilename = str_replace($currentDate, $realDate, $filename);
        $newFilePath = $dir . '/' . $newFilename;

        // Vérifier collision
        if (file_exists($newFilePath) && $newFilePath !== $filePath) {
            $this->io->newLine();
            $this->io->warning("  Collision ! {$newFilename} existe déjà, fichier ignoré.");
            return 'errors';
        }

        if ($this->io->isVerbose()) {
            $this->io->newLine();
            $this->io->text(sprintf(
                '  <info>%s</info> → <comment>%s</comment> (date visite: %s)',
                $filename,
                $newFilename,
                $realDate
            ));
        }

        if (!$dryRun) {
            if (!rename($filePath, $newFilePath)) {
                $this->io->newLine();
                $this->io->error("  Échec du renommage : {$filename}");
                return 'errors';
            }

            // Mettre à jour la BDD si demandé
            if ($updateDb) {
                $this->updateDatabase($filePath, $newFilePath, $realDate);
                return 'db_updated';
            }
        }

        return 'renamed';
    }

    /**
     * Extrait la date de visite depuis le contenu texte du PDF
     * 
     * Cherche le pattern "Date : DD/MM/YYYY" ou "Date :DD/MM/YYYY"
     * Exclut "Date de réponse" qui est un champ différent
     * 
     * @return string|null Date au format YYYY-MM-DD ou null
     */
    private function extractVisitDate(string $text): ?string
    {
        // Normaliser les espaces et retours à la ligne
        $text = preg_replace('/\r\n|\r/', "\n", $text);

        // Pattern 1 : "Date : DD/MM/YYYY" seul sur une ligne ou après un saut de ligne
        // On cherche "Date" qui n'est PAS précédé de "de réponse" ou "effective" ou "prévue"
        // Le pattern le plus fiable vu les screenshots : ligne qui commence par "Date : " suivi d'une date
        if (preg_match('/(?:^|\n)\s*Date\s*:\s*(\d{2}\/\d{2}\/\d{4})/m', $text, $matches)) {
            return $this->convertDateToIso($matches[1]);
        }

        // Pattern 2 : "Date :DD/MM/YYYY" (sans espace après les deux-points)
        if (preg_match('/(?:^|\n)\s*Date\s*:(\d{2}\/\d{2}\/\d{4})/m', $text, $matches)) {
            return $this->convertDateToIso($matches[1]);
        }

        // Pattern 3 : fallback — chercher "Date" suivi d'une date, mais PAS "Date de réponse"
        // ni "Date effective" ni "Date prévisionnelle"
        if (preg_match('/(?<!réponse\s)(?<!effective\s)(?<!prévisionnelle\s)Date\s*:?\s*(\d{2}\/\d{2}\/\d{4})/i', $text, $matches)) {
            return $this->convertDateToIso($matches[1]);
        }

        return null;
    }

    /**
     * Convertit DD/MM/YYYY en YYYY-MM-DD
     */
    private function convertDateToIso(string $frenchDate): ?string
    {
        $parts = explode('/', $frenchDate);
        if (count($parts) !== 3) return null;

        [$day, $month, $year] = $parts;

        // Validation basique
        if (!checkdate((int)$month, (int)$day, (int)$year)) {
            return null;
        }

        return sprintf('%s-%s-%s', $year, $month, $day);
    }

    /**
     * Extrait la date YYYY-MM-DD du nom de fichier
     * Format attendu : {CLIENT}-{YYYY-MM-DD}-{VISITE}-{DATA_ID}.pdf
     */
    private function extractDateFromFilename(string $filename): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Met à jour kizeo_jobs.local_path et equipement_sXX.date_derniere_visite
     */
    private function updateDatabase(string $oldPath, string $newPath, string $realDate): void
    {
        // 1. Mettre à jour kizeo_jobs.local_path (si le job existe encore)
        try {
            $this->connection->executeStatement(
                'UPDATE kizeo_jobs SET local_path = :new_path WHERE local_path = :old_path',
                ['new_path' => $newPath, 'old_path' => $oldPath]
            );
        } catch (\Exception $e) {
            // Pas grave si le job a été purgé
        }

        // 2. Extraire les infos du chemin pour mettre à jour l'équipement
        // Path : .../storage/pdf/{agency}/{id_contact}/{annee}/{visite}/{filename}
        $parts = explode('/', str_replace('\\', '/', $newPath));
        $count = count($parts);

        if ($count < 5) return;

        $visite = $parts[$count - 2];     // CEA, CE1, CE2...
        $annee = $parts[$count - 3];      // 2025, 2026...
        $idContact = $parts[$count - 4];  // 1146...
        $agencyCode = $parts[$count - 5]; // S50...

        // Table agence = equipement_sXX (minuscule)
        $table = 'equipement_' . strtolower($agencyCode);

        // Mettre à jour date_derniere_visite pour tous les équipements de ce client/visite/année
        try {
            $sql = sprintf(
                'UPDATE %s SET date_derniere_visite = :date WHERE id_contact = :id_contact AND visite = :visite AND annee = :annee',
                $table
            );
            $this->connection->executeStatement($sql, [
                'date' => $realDate,
                'id_contact' => (int) $idContact,
                'visite' => strtoupper($visite),
                'annee' => $annee,
            ]);
        } catch (\Exception $e) {
            // Log silencieux — la table peut ne pas exister pour certains codes
        }
    }
}
