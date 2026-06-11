<?php

namespace App\Command\Kizeo;

use App\Entity\KizeoJob;
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
 * Commande CRON principale - Récupération des CR Techniciens Kizeo
 * 
 * Récupère les formulaires non lus, extrait les équipements, 
 * les persiste en BDD et crée les jobs de téléchargement (PDF/photos).
 * 
 * Usage:
 *   php bin/console app:kizeo:fetch-forms                    # Toutes les agences
 *   php bin/console app:kizeo:fetch-forms --agency=S60       # Une seule agence
 *   php bin/console app:kizeo:fetch-forms --limit=5          # Limite par agence
 *   php bin/console app:kizeo:fetch-forms --dry-run          # Simulation
 *   php bin/console app:kizeo:fetch-forms -v                 # Verbose
 * 
 * Fréquence CRON recommandée: Toutes les 2 heures
 *  /usr/bin/php /path/to/project/bin/console app:kizeo:fetch-forms >> /path/to/logs/kizeo-fetch.log 2>&1
 * 
 * CORRECTION 30/01/2026:
 * - L'endpoint /data/unread retourne une liste SIMPLIFIÉE sans les fields
 * - Il faut appeler getFormData() pour chaque CR afin de récupérer les données complètes
 */
#[AsCommand(
    name: 'app:kizeo:fetch-forms',
    description: 'Récupère les CR Kizeo non lus, enregistre les équipements et crée les jobs de téléchargement',
)]
class FetchFormsCommand extends Command
{
    private const DEFAULT_LIMIT = 10;
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
            ->addOption(
                'agency',
                'a',
                InputOption::VALUE_REQUIRED,
                'Traiter une seule agence (code: S10, S40, S60, etc.)'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Nombre maximum de CR à récupérer par agence',
                self::DEFAULT_LIMIT
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Mode simulation : affiche ce qui serait fait sans modifier la BDD'
            )
            ->addOption(
                'skip-mark-read',
                null,
                InputOption::VALUE_NONE,
                'Ne pas marquer les CR comme lus (pour debug/tests)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agencyCode = $input->getOption('agency');
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');
        $skipMarkRead = $input->getOption('skip-mark-read');

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Titre
        $io->title('SOMAFI - Récupération CR Techniciens Kizeo');
        $io->text(sprintf('📅 %s', (new \DateTime())->format('d/m/Y H:i:s')));

        if ($dryRun) {
            $io->warning('🔍 Mode DRY-RUN activé - Aucune modification en BDD');
        }
        if ($skipMarkRead) {
            $io->warning('⚠️ Option --skip-mark-read activée - Les CR ne seront PAS marqués comme lus');
        }

        $this->kizeoLogger->info('=== DÉBUT FETCH FORMS ===', [
            'agency_filter' => $agencyCode,
            'limit' => $limit,
            'dry_run' => $dryRun,
        ]);

        // Récupérer les agences à traiter
        $agencies = $this->getAgenciesToProcess($agencyCode);
        
        if (empty($agencies)) {
            $io->error($agencyCode 
                ? sprintf('Agence "%s" non trouvée ou sans formulaire Kizeo configuré', $agencyCode)
                : 'Aucune agence configurée avec un formulaire Kizeo'
            );
            return Command::FAILURE;
        }

        $io->section(sprintf('📋 %d agence(s) à traiter', count($agencies)));

        // Stats globales
        $globalStats = [
            'agencies_processed' => 0,
            'forms_processed' => 0,
            'equipments_created' => 0,
            'equipments_skipped' => 0,
            'photos_created' => 0,
            'jobs_created' => 0,
            'errors' => 0,
        ];

        // Traiter chaque agence
        foreach ($agencies as $agency) {
            $agencyStats = $this->processAgency($agency, $limit, $dryRun, $skipMarkRead, $io, $output);
            
            $globalStats['agencies_processed']++;
            $globalStats['forms_processed'] += $agencyStats['forms'];
            $globalStats['equipments_created'] += $agencyStats['equipments_created'];
            $globalStats['equipments_skipped'] += $agencyStats['equipments_skipped'];
            $globalStats['photos_created'] += $agencyStats['photos_created'];
            $globalStats['jobs_created'] += $agencyStats['jobs_created'];
            $globalStats['errors'] += $agencyStats['errors'];

            // Vérification mémoire entre les agences
            $this->checkMemoryUsage($io);
        }

        // Résumé final
        $duration = round(microtime(true) - $startTime, 2);
        $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

        $io->newLine();
        $io->section('📊 Résumé global');
        
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Agences traitées', $globalStats['agencies_processed']],
                ['CR traités', $globalStats['forms_processed']],
                ['Équipements créés', $globalStats['equipments_created']],
                ['Équipements ignorés (doublons)', $globalStats['equipments_skipped']],
                ['Photos référencées', $globalStats['photos_created']],
                ['Jobs créés (PDF+Photos)', $globalStats['jobs_created']],
                ['Erreurs', $globalStats['errors']],
                ['Durée', sprintf('%s sec', $duration)],
                ['Mémoire pic', sprintf('%s MB', $memoryPeak)],
            ]
        );

        $this->kizeoLogger->info('=== FIN FETCH FORMS ===', $globalStats + [
            'duration_sec' => $duration,
            'memory_peak_mb' => $memoryPeak,
        ]);

        // Message final
        if ($globalStats['errors'] > 0) {
            $io->warning(sprintf('⚠️ Terminé avec %d erreur(s)', $globalStats['errors']));
            return Command::FAILURE;
        }

        if ($globalStats['forms_processed'] === 0) {
            $io->success('✅ Aucun nouveau CR à traiter');
        } else {
            $io->success(sprintf(
                '✅ %d CR traités, %d équipements créés, %d photos référencées, %d jobs créés',
                $globalStats['forms_processed'],
                $globalStats['equipments_created'],
                $globalStats['photos_created'],
                $globalStats['jobs_created']
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Récupère les agences à traiter
     * 
     * @return array<\App\Entity\Agency>
     */
    private function getAgenciesToProcess(?string $agencyCode): array
    {
        if ($agencyCode) {
            $agency = $this->agencyRepository->findOneBy(['code' => strtoupper($agencyCode)]);
            if (!$agency || !$agency->getKizeoFormId()) {
                return [];
            }
            return [$agency];
        }

        // Toutes les agences avec un form_id configuré
        return $this->agencyRepository->findBy(['isActive' => true]);
    }

    /**
     * Traite une agence
     * 
     * @return array{forms: int, equipments_created: int, equipments_skipped: int, jobs_created: int, errors: int}
     */
    private function processAgency(
        $agency,
        int $limit,
        bool $dryRun,
        bool $skipMarkRead,
        SymfonyStyle $io,
        OutputInterface $output
    ): array {
        $agencyCode = $agency->getCode();
        $formId = $agency->getKizeoFormId();

        $stats = [
            'forms' => 0,
            'equipments_created' => 0,
            'equipments_skipped' => 0,
            'photos_created' => 0,
            'jobs_created' => 0,
            'errors' => 0,
        ];

        $io->text(sprintf('🏢 <info>%s</info> - Form ID: %d', $agencyCode, $formId ?? 0));

        if (!$formId) {
            $io->text('   ⚠️ Pas de formulaire Kizeo configuré, ignoré');
            $this->kizeoLogger->warning('Agence sans form_id', ['agency' => $agencyCode]);
            return $stats;
        }

        try {
            // Récupérer les CR non lus (liste SIMPLIFIÉE - juste les IDs)
            $unreadForms = $this->kizeoApi->getUnreadForms($formId, $limit);

            if (empty($unreadForms)) {
                $io->text('   ℹ️ Aucun CR non lu');
                return $stats;
            }

            $io->text(sprintf('   📥 %d CR non lu(s) récupéré(s)', count($unreadForms)));

            // Traiter chaque CR
            // ⚠️ CORRECTION 30/01/2026: L'endpoint /unread retourne une liste simplifiée
            // Il faut appeler getFormData() pour récupérer les données COMPLÈTES avec les fields
            foreach ($unreadForms as $unreadItem) {
                $dataId = $unreadItem['id'] ?? $unreadItem['_id'] ?? null;
                
                if (!$dataId) {
                    $this->kizeoLogger->warning('CR sans data_id dans liste unread', ['item' => $unreadItem]);
                    $stats['errors']++;
                    continue;
                }
                
                // Récupérer les données COMPLÈTES avec les fields
                $formData = $this->kizeoApi->getFormData($formId, (int) $dataId);
                
                if ($formData === null) {
                    $this->kizeoLogger->warning('Impossible de récupérer les détails du CR', [
                        'form_id' => $formId,
                        'data_id' => $dataId,
                    ]);
                    $io->text(sprintf('      ⚠️ CR #%s - Impossible de récupérer les détails', $dataId));
                    $stats['errors']++;
                    continue;
                }
                
                $formStats = $this->processForm($agencyCode, $formId, $formData, $dryRun, $skipMarkRead, $io, $output);
                
                $stats['forms']++;
                $stats['equipments_created'] += $formStats['equipments_created'];
                $stats['equipments_skipped'] += $formStats['equipments_skipped'];
                $stats['photos_created'] += $formStats['photos_created'];
                $stats['jobs_created'] += $formStats['jobs_created'];
                $stats['errors'] += $formStats['errors'];
            }

            // Flush en fin d'agence
            if (!$dryRun) {
                $this->em->flush();
                $this->em->clear();
            }

        } catch (\Exception $e) {
            $stats['errors']++;
            $io->text(sprintf('   ❌ Erreur API: %s', $e->getMessage()));
            $this->kizeoLogger->error('Erreur traitement agence', [
                'agency' => $agencyCode,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Traite un formulaire CR
     * 
     * @param array<string, mixed> $formData Données brutes du formulaire Kizeo
     * @return array{equipments_created: int, equipments_skipped: int, jobs_created: int, errors: int}
     */
    private function processForm(
        string $agencyCode,
        int $formId,
        array $formData,
        bool $dryRun,
        bool $skipMarkRead,
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

        $dataId = $formData['id'] ?? $formData['_id'] ?? null;
        
        if (!$dataId) {
            $this->kizeoLogger->warning('CR sans data_id', ['formData' => array_keys($formData)]);
            $stats['errors']++;
            return $stats;
        }

        $isVerbose = $output->isVerbose();

        try {
            // 1. EXTRACTION : Parser le JSON → DTO
            $extractedData = $this->formDataExtractor->extract($formData, $formId);

            if (!$extractedData->idContact) {
                $this->kizeoLogger->warning('CR sans id_contact', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                ]);
                $stats['errors']++;
                return $stats;
            }

            if ($isVerbose) {
                $visite = $this->determineVisiteFromExtractedData($extractedData);
                $io->text(sprintf(
                    '      📄 CR #%d - Client: %s (ID: %d) - %s',
                    $dataId,
                    $extractedData->raisonSociale ?? 'N/A',
                    $extractedData->idContact,
                    $visite
                ));
            }

            // 2. PERSISTANCE ÉQUIPEMENTS : Contrat + Hors contrat
            $generatedNumbers = []; // Pour les numéros générés hors contrat
            
            if (!$dryRun) {
                $persistResult = $this->equipmentPersister->persist(
                    $extractedData,
                    $agencyCode,
                    $formId,
                    (int) $dataId
                );
                
                $stats['equipments_created'] = $persistResult['inserted_contract'] + $persistResult['inserted_offcontract'];
                $stats['equipments_updated'] = $persistResult['updated_contract'];
                $stats['equipments_skipped'] = $persistResult['skipped_contract'] + $persistResult['skipped_offcontract'];
                $generatedNumbers = $persistResult['generated_numbers'];
            } else {
                // En dry-run, compter ce qui serait créé
                $stats['equipments_created'] = count($extractedData->contractEquipments) 
                    + count($extractedData->offContractEquipments);
            }

            if ($isVerbose) {
                $io->text(sprintf(
                    '         ✅ %d équip. créés, %d ignorés',
                    $stats['equipments_created'],
                    $stats['equipments_skipped']
                ));
            }

            // 2.5. PERSISTANCE PHOTOS : Référencer les médias dans la table `photos`
            if (!$dryRun) {
                $photoResult = $this->photoPersister->persist(
                    $extractedData,
                    $agencyCode,
                    $formId,
                    (int) $dataId,
                    $generatedNumbers
                );
                $stats['photos_created'] = $photoResult['created'];
            } else {
                $stats['photos_created'] = count($extractedData->contractEquipments)
                    + count($extractedData->offContractEquipments);
            }

            if ($isVerbose) {
                $io->text(sprintf('         📷 %d référence(s) photo créée(s)', $stats['photos_created']));
            }

            // 3. CRÉATION JOBS : PDF + Photos
            if (!$dryRun) {
                $jobsResult = $this->jobCreator->createJobs($extractedData, $agencyCode, $generatedNumbers);
                $stats['jobs_created'] = ($jobsResult['pdf_created'] ? 1 : 0) + $jobsResult['photos_created'];
            } else {
                // En dry-run, compter : 1 PDF + N photos
                $stats['jobs_created'] = 1 + count($extractedData->medias);
            }

            if ($isVerbose) {
                $io->text(sprintf('         📝 %d job(s) créés', $stats['jobs_created']));
            }

            // 4. MARQUER COMME LU (CRITIQUE pour éviter doublons)
            if (!$dryRun && !$skipMarkRead) {
                $marked = $this->kizeoApi->markAsRead($formId, (int) $dataId);
                if (!$marked) {
                    $this->kizeoLogger->warning('Échec markAsRead', [
                        'form_id' => $formId,
                        'data_id' => $dataId,
                    ]);
                }
            }

            $this->kizeoLogger->info('CR traité avec succès', [
                'agency' => $agencyCode,
                'form_id' => $formId,
                'data_id' => $dataId,
                'id_contact' => $extractedData->idContact,
                'equipments_created' => $stats['equipments_created'],
                'photos_created' => $stats['photos_created'],
                'jobs_created' => $stats['jobs_created'],
            ]);

        } catch (\Exception $e) {
            $stats['errors']++;
            $this->kizeoLogger->error('Erreur traitement CR', [
                'agency' => $agencyCode,
                'form_id' => $formId,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($output->isVerbose()) {
                $io->text(sprintf('      ❌ Erreur CR #%d: %s', $dataId, $e->getMessage()));
            }
        }

        return $stats;
    }

    /**
     * Détermine la visite principale depuis les données extraites
     * 
     * @param \App\DTO\Kizeo\ExtractedFormData $extractedData
     */
    private function determineVisiteFromExtractedData($extractedData): string
    {
        // Chercher dans les équipements au contrat
        foreach ($extractedData->contractEquipments as $equipment) {
            if ($equipment->hasValidVisite()) {
                return $equipment->getNormalizedVisite();
            }
        }

        // Défaut
        return 'CE1';
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
            
            $this->kizeoLogger->info('Memory cleanup triggered', [
                'before_mb' => round($currentMemory / 1024 / 1024, 1),
                'after_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            ]);
        }
    }
}