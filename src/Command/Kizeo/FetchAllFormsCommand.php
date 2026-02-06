<?php

namespace App\Command\Kizeo;

use App\Repository\AgencyRepository;
use App\Repository\KizeoJobRepository;
use App\Service\Kizeo\EquipmentPersister;
use App\Service\Kizeo\FormDataExtractor;
use App\Service\Kizeo\JobCreator;
use App\Service\Kizeo\KizeoApiService;
use App\Service\Kizeo\PhotoPersister;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de récupération COMPLÈTE des CR Kizeo (bypass lu/non-lu)
 * 
 * Utilise /data/all au lieu de /data/unread pour récupérer TOUS les CR,
 * puis s'appuie sur la déduplication existante pour ignorer les doublons.
 * 
 * Créée le 05/02/2026 pour récupérer les ~3 900 CR manquants après
 * l'échec du markasunreadbyaction/read.
 * 
 * Usage:
 *   php bin/console app:kizeo:fetch-all                     # Toutes les agences
 *   php bin/console app:kizeo:fetch-all --agency=S60         # Une seule agence
 *   php bin/console app:kizeo:fetch-all --chunk=20           # 20 CR par batch
 *   php bin/console app:kizeo:fetch-all --offset=100         # Reprendre à partir du 101ème
 *   php bin/console app:kizeo:fetch-all --max=500            # Maximum 500 CR par agence
 *   php bin/console app:kizeo:fetch-all --dry-run            # Simulation
 *   php bin/console app:kizeo:fetch-all --skip-jobs          # Sans jobs PDF/photos
 * 
 * Stratégie mémoire (O2switch):
 *   - Traitement par chunks de 10 CR (configurable)
 *   - flush + clear de Doctrine entre chaque chunk
 *   - Pause de 200ms entre chaque appel API
 *   - Seuil mémoire à 200 MB → GC forcé
 */
#[AsCommand(
    name: 'app:kizeo:fetch-all',
    description: 'Récupère TOUS les CR Kizeo (bypass lu/non-lu) et remplit les tables équipements',
)]
class FetchAllFormsCommand extends Command
{
    private const DEFAULT_CHUNK_SIZE = 10;
    private const API_DELAY_MS = 200_000; // 200ms entre chaque appel API
    private const MEMORY_CHECK_THRESHOLD = 200 * 1024 * 1024; // 200 MB

    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly FormDataExtractor $formDataExtractor,
        private readonly EquipmentPersister $equipmentPersister,
        private readonly PhotoPersister $photoPersister,
        private readonly JobCreator $jobCreator,
        private readonly AgencyRepository $agencyRepository,
        private readonly KizeoJobRepository $jobRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $kizeoLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agency', 'a', InputOption::VALUE_REQUIRED,
                'Traiter une seule agence (code: S10, S40, S60, etc.)')
            ->addOption('chunk', 'c', InputOption::VALUE_REQUIRED,
                'Nombre de CR à traiter par batch avant flush',
                self::DEFAULT_CHUNK_SIZE)
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED,
                'Index de départ dans la liste des data_ids (pour reprendre après interruption)',
                0)
            ->addOption('max', 'm', InputOption::VALUE_REQUIRED,
                'Nombre maximum de CR à traiter par agence (0 = tous)',
                0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Mode simulation : affiche ce qui serait fait sans modifier la BDD')
            ->addOption('skip-jobs', null, InputOption::VALUE_NONE,
                'Ne pas créer les jobs PDF/photos (utile pour ne remplir que les tables équipements)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agencyCode = $input->getOption('agency');
        $chunkSize = (int) $input->getOption('chunk');
        $offset = (int) $input->getOption('offset');
        $maxPerAgency = (int) $input->getOption('max');
        $dryRun = $input->getOption('dry-run');
        $skipJobs = $input->getOption('skip-jobs');

        $startTime = microtime(true);

        // Titre
        $io->title('SOMAFI - Récupération COMPLÈTE des CR Kizeo (bypass lu/non-lu)');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));
        $io->text(sprintf('⚙️  Chunk: %d | Offset: %d | Max/agence: %s',
            $chunkSize, $offset, $maxPerAgency > 0 ? $maxPerAgency : 'illimité'));

        if ($dryRun) {
            $io->warning('🔍 Mode DRY-RUN activé');
        }
        if ($skipJobs) {
            $io->warning('⏭️ Option --skip-jobs : pas de création de jobs PDF/photos');
        }

        $this->kizeoLogger->info('=== DÉBUT FETCH-ALL ===', [
            'agency_filter' => $agencyCode,
            'chunk_size' => $chunkSize,
            'offset' => $offset,
            'max_per_agency' => $maxPerAgency,
            'dry_run' => $dryRun,
        ]);

        // Récupérer les agences
        $agencies = $this->getAgenciesToProcess($agencyCode);

        if (empty($agencies)) {
            $io->error($agencyCode
                ? sprintf('Agence "%s" non trouvée ou sans formulaire Kizeo', $agencyCode)
                : 'Aucune agence configurée');
            return Command::FAILURE;
        }

        // Stats globales
        $globalStats = [
            'agencies_processed' => 0,
            'total_data_ids' => 0,
            'forms_processed' => 0,
            'equipments_created' => 0,
            'equipments_skipped' => 0,
            'photos_created' => 0,
            'jobs_created' => 0,
            'errors' => 0,
        ];

        // Traiter chaque agence
        foreach ($agencies as $agency) {
            $agencyStats = $this->processAgency(
                $agency, $chunkSize, $offset, $maxPerAgency,
                $dryRun, $skipJobs, $io, $output
            );

            $globalStats['agencies_processed']++;
            $globalStats['total_data_ids'] += $agencyStats['total_data_ids'];
            $globalStats['forms_processed'] += $agencyStats['forms'];
            $globalStats['equipments_created'] += $agencyStats['equipments_created'];
            $globalStats['equipments_skipped'] += $agencyStats['equipments_skipped'];
            $globalStats['photos_created'] += $agencyStats['photos_created'];
            $globalStats['jobs_created'] += $agencyStats['jobs_created'];
            $globalStats['errors'] += $agencyStats['errors'];

            $this->checkMemoryUsage($io);
        }

        // Résumé final
        $duration = round(microtime(true) - $startTime, 2);
        $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

        $io->newLine();
        $io->section('📊 Résumé global FETCH-ALL');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Agences traitées', $globalStats['agencies_processed']],
                ['Data IDs trouvés (total)', $globalStats['total_data_ids']],
                ['CR traités', $globalStats['forms_processed']],
                ['Équipements créés', $globalStats['equipments_created']],
                ['Équipements ignorés (dédup)', $globalStats['equipments_skipped']],
                ['Photos référencées', $globalStats['photos_created']],
                ['Jobs créés (PDF+Photos)', $globalStats['jobs_created']],
                ['Erreurs / CR skippés', $globalStats['errors']],
                ['Durée', sprintf('%s sec (~%s min)', $duration, round($duration / 60, 1))],
                ['Mémoire pic', sprintf('%s MB', $memoryPeak)],
            ]
        );

        $this->kizeoLogger->info('=== FIN FETCH-ALL ===', $globalStats + [
            'duration_sec' => $duration,
            'memory_peak_mb' => $memoryPeak,
        ]);

        if ($globalStats['errors'] > 0) {
            $io->warning(sprintf('⚠️ Terminé avec %d erreur(s)/skip(s)', $globalStats['errors']));
        }

        $io->success(sprintf(
            '✅ %d CR traités sur %d data_ids, %d équipements créés, %d ignorés (dédup)',
            $globalStats['forms_processed'],
            $globalStats['total_data_ids'],
            $globalStats['equipments_created'],
            $globalStats['equipments_skipped']
        ));

        return Command::SUCCESS;
    }

    // =========================================================================
    // MÉTHODES PRIVÉES
    // =========================================================================

    private function getAgenciesToProcess(?string $agencyCode): array
    {
        if ($agencyCode) {
            $agency = $this->agencyRepository->findOneBy(['code' => strtoupper($agencyCode)]);
            if (!$agency || !$agency->getKizeoFormId()) {
                return [];
            }
            return [$agency];
        }

        return $this->agencyRepository->findBy(['isActive' => true]);
    }

    /**
     * Traite une agence en récupérant TOUS ses data_ids
     */
    private function processAgency(
        $agency,
        int $chunkSize,
        int $offset,
        int $maxPerAgency,
        bool $dryRun,
        bool $skipJobs,
        SymfonyStyle $io,
        OutputInterface $output
    ): array {
        $agencyCode = $agency->getCode();
        $formId = $agency->getKizeoFormId();

        $stats = [
            'total_data_ids' => 0,
            'forms' => 0,
            'equipments_created' => 0,
            'equipments_skipped' => 0,
            'photos_created' => 0,
            'jobs_created' => 0,
            'errors' => 0,
        ];

        $io->newLine();
        $io->section(sprintf('🏢 %s - Form ID: %d', $agencyCode, $formId ?? 0));

        if (!$formId) {
            $io->text('   ⚠️ Pas de formulaire Kizeo configuré, ignoré');
            return $stats;
        }

        try {
            // ===============================================================
            // ÉTAPE 1 : Récupérer TOUS les data_ids via /data/all
            // ===============================================================
            $allDataIds = $this->kizeoApi->getAllDataIdsForForm($formId);
            $stats['total_data_ids'] = count($allDataIds);

            if (empty($allDataIds)) {
                $io->text('   ℹ️ Aucun CR trouvé sur Kizeo');
                return $stats;
            }

            $io->text(sprintf('   📋 %d data_ids trouvés au total', count($allDataIds)));

            // Appliquer l'offset (pour reprendre après interruption)
            if ($offset > 0) {
                $allDataIds = array_slice($allDataIds, $offset);
                $io->text(sprintf('   ⏩ Offset %d appliqué → %d restants', $offset, count($allDataIds)));
            }

            // Appliquer le max par agence
            if ($maxPerAgency > 0 && count($allDataIds) > $maxPerAgency) {
                $allDataIds = array_slice($allDataIds, 0, $maxPerAgency);
                $io->text(sprintf('   🔒 Limité à %d CR (--max)', $maxPerAgency));
            }

            // ===============================================================
            // ÉTAPE 2 : Traiter par chunks
            // ===============================================================
            $chunks = array_chunk($allDataIds, $chunkSize);
            $totalChunks = count($chunks);
            $processedCount = 0;

            foreach ($chunks as $chunkIndex => $chunkDataIds) {
                $io->text(sprintf(
                    '   📦 Batch %d/%d (%d CR)',
                    $chunkIndex + 1, $totalChunks, count($chunkDataIds)
                ));

                foreach ($chunkDataIds as $dataId) {
                    $processedCount++;
                    $formStats = $this->processSingleCR(
                        $agencyCode, $formId, (int) $dataId,
                        $dryRun, $skipJobs, $io, $output
                    );

                    $stats['forms']++;
                    $stats['equipments_created'] += $formStats['equipments_created'];
                    $stats['equipments_skipped'] += $formStats['equipments_skipped'];
                    $stats['photos_created'] += $formStats['photos_created'];
                    $stats['jobs_created'] += $formStats['jobs_created'];
                    $stats['errors'] += $formStats['errors'];

                    // Pause entre les appels API
                    usleep(self::API_DELAY_MS);
                }

                // Flush + clear Doctrine après chaque chunk
                if (!$dryRun) {
                    $this->em->flush();
                    $this->em->clear();
                }

                // Vérification mémoire
                $this->checkMemoryUsage($io);

                // Progress
                $io->text(sprintf(
                    '      → %d/%d traités | Créés: %d | Dédup: %d | Erreurs: %d',
                    $processedCount, count($allDataIds),
                    $stats['equipments_created'],
                    $stats['equipments_skipped'],
                    $stats['errors']
                ));
            }

        } catch (\Exception $e) {
            $stats['errors']++;
            $io->error(sprintf('   ❌ Erreur agence %s: %s', $agencyCode, $e->getMessage()));
            $this->kizeoLogger->error('Erreur traitement agence (fetch-all)', [
                'agency' => $agencyCode,
                'error' => $e->getMessage(),
            ]);
        }

        // Résumé agence
        $io->text(sprintf(
            '   ✅ %s terminé : %d CR, %d créés, %d ignorés, %d erreurs',
            $agencyCode, $stats['forms'],
            $stats['equipments_created'], $stats['equipments_skipped'], $stats['errors']
        ));

        return $stats;
    }

    /**
     * Traite UN CR identifié par son data_id
     * 
     * Logique identique à FetchFormsCommand::processForm() mais :
     * - Pas de markAsRead (on ne touche pas au flag lu/non-lu)
     * - Appel à getFormData() pour récupérer les données complètes
     */
    private function processSingleCR(
        string $agencyCode,
        int $formId,
        int $dataId,
        bool $dryRun,
        bool $skipJobs,
        SymfonyStyle $io,
        OutputInterface $output
    ): array {
        $stats = [
            'equipments_created' => 0,
            'equipments_skipped' => 0,
            'photos_created' => 0,
            'jobs_created' => 0,
            'errors' => 0,
        ];

        $isVerbose = $output->isVerbose();

        try {
            // 1. Récupérer les données COMPLÈTES du CR
            $formData = $this->kizeoApi->getFormData($formId, $dataId);

            if ($formData === null) {
                if ($isVerbose) {
                    $io->text(sprintf('      ⚠️ CR #%d - Impossible de récupérer les détails', $dataId));
                }
                $this->kizeoLogger->warning('Impossible de récupérer détails CR (fetch-all)', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                ]);
                $stats['errors']++;
                return $stats;
            }

            // 2. EXTRACTION : Parser le JSON → DTO
            $extractedData = $this->formDataExtractor->extract($formData, $formId);

            if (!$extractedData->idContact) {
                if ($isVerbose) {
                    $io->text(sprintf('      ⏭️ CR #%d - Sans id_contact (skip)', $dataId));
                }
                $this->kizeoLogger->warning('CR sans id_contact (fetch-all)', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                ]);
                $stats['errors']++;
                return $stats;
            }

            if ($isVerbose) {
                $io->text(sprintf(
                    '      📄 CR #%d - Client: %s (ID: %s)',
                    $dataId,
                    $extractedData->raisonSociale ?? 'N/A',
                    $extractedData->idContact
                ));
            }

            // 3. PERSISTANCE ÉQUIPEMENTS (avec déduplication automatique)
            $generatedNumbers = [];

            if (!$dryRun) {
                $persistResult = $this->equipmentPersister->persist(
                    $extractedData,
                    $agencyCode,
                    $formId,
                    $dataId
                );

                $stats['equipments_created'] = $persistResult['inserted_contract'] + $persistResult['inserted_offcontract'];
                $stats['equipments_skipped'] = $persistResult['skipped_contract'] + $persistResult['skipped_offcontract'];
                $generatedNumbers = $persistResult['generated_numbers'];
            } else {
                $stats['equipments_created'] = count($extractedData->contractEquipments)
                    + count($extractedData->offContractEquipments);
            }

            // 4. PERSISTANCE PHOTOS
            if (!$dryRun) {
                $photoResult = $this->photoPersister->persist(
                    $extractedData,
                    $agencyCode,
                    $formId,
                    $dataId,
                    $generatedNumbers
                );
                $stats['photos_created'] = $photoResult['created'];
            }

            // 5. CRÉATION JOBS (sauf si --skip-jobs)
            if (!$dryRun && !$skipJobs) {
                $jobsResult = $this->jobCreator->createJobs($extractedData, $agencyCode, $generatedNumbers);
                $stats['jobs_created'] = ($jobsResult['pdf_created'] ? 1 : 0) + $jobsResult['photos_created'];
            }

            // PAS de markAsRead ici — on ne touche pas au flag lu/non-lu

            if ($isVerbose) {
                $io->text(sprintf(
                    '         ✅ %d créés, %d ignorés, %d photos, %d jobs',
                    $stats['equipments_created'],
                    $stats['equipments_skipped'],
                    $stats['photos_created'],
                    $stats['jobs_created']
                ));
            }

        } catch (\Exception $e) {
            $stats['errors']++;

            // Réouvrir l'EntityManager s'il a été fermé par une exception SQL
            if (!$this->em->isOpen()) {
                $this->em = $this->em->create(
                    $this->em->getConnection(),
                    $this->em->getConfiguration()
                );
            }

            $this->kizeoLogger->error('Erreur traitement CR (fetch-all)', [
                'agency' => $agencyCode,
                'form_id' => $formId,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);

            if ($isVerbose) {
                $io->text(sprintf('      ❌ CR #%d: %s', $dataId, $e->getMessage()));
            }
        }

        return $stats;
    }

    /**
     * Vérifie l'utilisation mémoire et déclenche un GC si nécessaire
     */
    private function checkMemoryUsage(SymfonyStyle $io): void
    {
        $currentMemory = memory_get_usage(true);

        if ($currentMemory > self::MEMORY_CHECK_THRESHOLD) {
            $this->em->clear();
            gc_collect_cycles();

            $this->kizeoLogger->info('Memory cleanup (fetch-all)', [
                'before_mb' => round($currentMemory / 1024 / 1024, 1),
                'after_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            ]);
        }
    }
}
