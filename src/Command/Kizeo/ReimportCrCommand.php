<?php

declare(strict_types=1);

namespace App\Command\Kizeo;

use App\DTO\Kizeo\ExtractedEquipment;
use App\Service\Kizeo\EquipmentPersister;
use App\Service\Kizeo\FormDataExtractor;
use App\Service\Kizeo\JobCreator;
use App\Service\Kizeo\KizeoApiService;
use App\Service\Kizeo\PhotoPersister;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ré-importe UN CR technicien précis pour rejouer l'UPSERT équipements.
 *
 * Contexte : avant la correction UPSERT du 11/06/2026, l'import sautait tout
 * équipement au contrat qui préexistait en BDD (saisie « Gestion de parc »,
 * génération en masse…) et JETAIT les données du CR (marque, n° série, mise en
 * service, statut, anomalies…). Cette commande rejoue l'import d'un CR pour
 * récupérer ces données perdues. Par défaut SANS effets de bord (ni markAsRead,
 * ni jobs PDF/photos) : seuls les équipements sont (ré)upsertés.
 *
 * --save-jobs : rejeu COMPLET (référence les photos + crée les jobs PDF/photos),
 * pour reproduire en PROD le comportement du cron `fetch-forms`.
 *
 * Source du CR (au choix) :
 *   --file=chemin.json     → lit un dump JSON local (réponse /forms/{f}/data/{d})
 *   --form-id / --data-id  → récupère le CR via l'API Kizeo
 *
 * Exemples :
 *   php bin/console app:kizeo:reimport-cr --agency=S170 \
 *       --file="C:\Users\admin\Downloads\response_1780987497598.json" --dry-run
 *   php bin/console app:kizeo:reimport-cr --agency=S170 --form-id=1094209 --data-id=269546888
 */
#[AsCommand(
    name: 'app:kizeo:reimport-cr',
    description: 'Ré-importe un CR Kizeo précis (UPSERT équipements) pour récupérer des données perdues',
)]
class ReimportCrCommand extends Command
{
    /** Champs « fiche équipement » affichés dans le diff dry-run. */
    private const DIFF_FIELDS = [
        'libelle_equipement', 'marque', 'numero_serie', 'mise_en_service',
        'mode_fonctionnement', 'hauteur', 'largeur', 'repere_site_client',
        'statut_equipement', 'anomalies',
    ];

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly FormDataExtractor $formDataExtractor,
        private readonly EquipmentPersister $equipmentPersister,
        private readonly PhotoPersister $photoPersister,
        private readonly JobCreator $jobCreator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED, 'Code agence (ex: S170)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Chemin d\'un dump JSON local du CR')
            ->addOption('form-id', null, InputOption::VALUE_REQUIRED, 'ID du formulaire Kizeo (si récupération API)')
            ->addOption('data-id', null, InputOption::VALUE_REQUIRED, 'ID de la soumission Kizeo (si récupération API)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : affiche le diff sans rien écrire')
            ->addOption('save-jobs', null, InputOption::VALUE_NONE,
                'Crée aussi les références photos et les jobs de téléchargement (PDF + photos) — pour un rejeu complet en PROD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $agency = strtoupper((string) $input->getOption('agency'));
        if ($agency === '') {
            $io->error('Option --agency obligatoire (ex: --agency=S170).');
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $saveJobs = (bool) $input->getOption('save-jobs');

        // ── 1. Charger le CR (fichier local OU API) ──────────────────────────
        [$formData, $formId, $dataId, $err] = $this->loadCr($input);
        if ($err !== null) {
            $io->error($err);
            return Command::FAILURE;
        }

        $tableName = 'equipement_' . strtolower($agency);

        // ── 2. Extraire ──────────────────────────────────────────────────────
        $extracted = $this->formDataExtractor->extract($formData, $formId);

        if ($extracted->idContact === null) {
            $io->error('CR sans id_contact exploitable.');
            return Command::FAILURE;
        }

        $io->section(sprintf(
            'CR #%d (form %d) — client id_contact %d — agence %s%s',
            $dataId, $formId, $extracted->idContact, $agency, $dryRun ? ' — DRY-RUN' : ''
        ));
        $io->writeln(sprintf(
            '  Date visite : %s | Équip. contrat : %d | Hors contrat : %d',
            $extracted->dateVisite?->format('Y-m-d') ?? 'N/A',
            count($extracted->contractEquipments),
            count($extracted->offContractEquipments),
        ));

        // ── 3. Aperçu : pour chaque équipement au contrat, action + diff ─────
        $rows = [];
        foreach ($extracted->contractEquipments as $equipment) {
            if (!$equipment->hasValidNumero()) {
                continue;
            }
            $numero = strtoupper(trim($equipment->numeroEquipement));
            $visite = $equipment->getNormalizedVisite() ?? 'CE1';

            $existing = $this->connection->fetchAssociative(
                sprintf(
                    'SELECT * FROM %s WHERE id_contact = ? AND numero_equipement = ? AND visite = ? AND annee = ? AND is_archive = 0 ORDER BY id DESC LIMIT 1',
                    $tableName
                ),
                [$extracted->idContact, $numero, $visite, $extracted->annee]
            ) ?: null;

            [$action, $changes] = $this->previewAction($existing, $equipment, $extracted->dateVisite?->format('Y-m-d'));

            $rows[] = [$numero, $visite, $action, $changes === [] ? '—' : implode("\n", $changes)];
        }

        if ($rows !== []) {
            $io->table(['N° équip.', 'Visite', 'Action', 'Champs remplis/mis à jour (avant → après)'], $rows);
        } else {
            $io->warning('Aucun équipement au contrat exploitable dans ce CR.');
        }

        // ── 4. Application ───────────────────────────────────────────────────
        if ($dryRun) {
            if ($saveJobs) {
                $io->note(sprintf(
                    'Dry-run : aucune écriture. --save-jobs créerait 1 job PDF + %d référence(s)/job(s) photo.',
                    count($extracted->medias)
                ));
            } else {
                $io->note('Dry-run : aucune écriture. Relancer sans --dry-run pour appliquer.');
            }
            return Command::SUCCESS;
        }

        $stats = $this->equipmentPersister->persist($extracted, $agency, $formId, $dataId);
        $generatedNumbers = $stats['generated_numbers'] ?? [];

        $io->success(sprintf(
            'UPSERT terminé : %d inséré(s), %d mis à jour, %d ignoré(s) (contrat) ; %d HC inséré(s).',
            $stats['inserted_contract'],
            $stats['updated_contract'],
            $stats['skipped_contract'],
            $stats['inserted_offcontract'],
        ));

        // ── 5. Photos + jobs (rejeu complet pour la PROD) ────────────────────
        if ($saveJobs) {
            $photoResult = $this->photoPersister->persist($extracted, $agency, $formId, $dataId, $generatedNumbers);
            $jobsResult = $this->jobCreator->createJobs($extracted, $agency, $generatedNumbers);

            $io->success(sprintf(
                'Jobs : %d référence(s) photo, %s job PDF, %d job(s) photo créé(s).',
                $photoResult['created'] ?? 0,
                ($jobsResult['pdf_created'] ?? false) ? '1' : '0',
                $jobsResult['photos_created'] ?? 0,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Charge le CR depuis un fichier local ou l'API Kizeo.
     *
     * @return array{0: array<string,mixed>|null, 1: int, 2: int, 3: string|null}
     *         [formData, formId, dataId, erreur]
     */
    private function loadCr(InputInterface $input): array
    {
        $file = $input->getOption('file');

        if ($file !== null) {
            if (!is_file($file)) {
                return [null, 0, 0, sprintf('Fichier introuvable : %s', $file)];
            }
            $json = json_decode((string) file_get_contents($file), true);
            if (!is_array($json)) {
                return [null, 0, 0, 'JSON invalide dans le fichier fourni.'];
            }
            // Accepte soit {status, data:{...}} (réponse API brute) soit l'objet data directement
            $data = $json['data'] ?? $json;
            $formId = (int) ($input->getOption('form-id') ?? $data['form_id'] ?? 0);
            $dataId = (int) ($data['id'] ?? 0);
            if ($formId === 0 || $dataId === 0) {
                return [null, 0, 0, 'Impossible de déduire form_id/data_id depuis le fichier (préciser --form-id).'];
            }
            return [$data, $formId, $dataId, null];
        }

        $formId = (int) ($input->getOption('form-id') ?? 0);
        $dataId = (int) ($input->getOption('data-id') ?? 0);
        if ($formId === 0 || $dataId === 0) {
            return [null, 0, 0, 'Fournir --file, ou --form-id ET --data-id.'];
        }

        $data = $this->kizeoApi->getFormData($formId, $dataId);
        if ($data === null) {
            return [null, 0, 0, sprintf('CR introuvable via API (form %d, data %d).', $formId, $dataId)];
        }
        return [$data, $formId, $dataId, null];
    }

    /**
     * Calcule l'action prévue (INSERT/UPDATE/SKIP) et la liste des changements,
     * en miroir de la logique UPSERT d'EquipmentPersister (sans rien écrire).
     *
     * @param array<string,mixed>|null $existing
     * @return array{0: string, 1: string[]}
     */
    private function previewAction(?array $existing, ExtractedEquipment $equipment, ?string $newDate): array
    {
        // Map champ BDD → valeur du CR
        $crValues = [
            'libelle_equipement'  => $equipment->libelleEquipement,
            'marque'              => $equipment->marque,
            'numero_serie'        => $equipment->numeroSerie,
            'mise_en_service'     => $equipment->miseEnService,
            'mode_fonctionnement' => $equipment->modeFonctionnement,
            'hauteur'             => $equipment->hauteur,
            'largeur'             => $equipment->largeur,
            'repere_site_client'  => $equipment->repereSiteClient,
            'statut_equipement'   => $equipment->statutEquipement,
            'anomalies'           => $equipment->anomalies,
        ];

        if ($existing === null) {
            $filled = [];
            foreach (self::DIFF_FIELDS as $col) {
                $new = $crValues[$col] ?? null;
                if ($new !== null && trim((string) $new) !== '') {
                    $filled[] = sprintf('%s = %s', $col, $this->short((string) $new));
                }
            }
            return ['INSERT', $filled];
        }

        $existingDate = $existing['date_derniere_visite'] ?? null;
        if (!$this->crIsNewer($existingDate, $newDate)) {
            return [sprintf('SKIP (CR %s ≤ %s)', $newDate ?? '∅', $existingDate ?? '∅'), []];
        }

        // CR plus récent → fusion défensive : on ne montre que les champs réellement modifiés
        $changes = [];
        foreach (self::DIFF_FIELDS as $col) {
            $new = $crValues[$col] ?? null;
            if ($new === null || trim((string) $new) === '') {
                continue; // preferNew conserve l'existant
            }
            $old = $existing[$col] ?? null;
            if ((string) $old !== trim((string) $new)) {
                $changes[] = sprintf('%s : %s → %s', $col, $this->short((string) ($old ?? '∅')), $this->short(trim((string) $new)));
            }
        }
        return ['UPDATE', $changes];
    }

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

    private function short(string $v): string
    {
        $v = trim($v);
        return mb_strlen($v) > 30 ? mb_substr($v, 0, 29) . '…' : $v;
    }
}
