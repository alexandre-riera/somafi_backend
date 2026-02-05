<?php

namespace App\Command\Kizeo;

use App\Service\Kizeo\KizeoApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:kizeo:mark-unread',
    description: 'Marque TOUS les CR de maintenance comme non lus sur Kizeo (reset avant re-import)',
)]
class MarkFormsUnreadCommand extends Command
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Reset : Marquage de tous les CR maintenance comme NON LUS');

        // 1. Récupérer les 13 formulaires MAINTENANCE
        $maintenanceForms = $this->kizeoApi->getMaintenanceForms();
        $io->info(sprintf('%d formulaires MAINTENANCE trouvés', count($maintenanceForms)));

        $totalMarked = 0;
        $totalErrors = 0;

        foreach ($maintenanceForms as $form) {
            $formId = (int) $form['id'];
            $formName = $form['name'] ?? 'N/A';
            $io->section("📋 $formName (form_id: $formId)");

            try {
                // 2. Récupérer TOUS les data_ids
                $allDataIds = $this->kizeoApi->getAllDataIdsForForm($formId);

                if (empty($allDataIds)) {
                    $io->comment('  → Aucun data trouvé, on passe.');
                    continue;
                }

                $io->info(sprintf('  → %d data_ids trouvés', count($allDataIds)));

                // 3. Marquer par batch de 500
                $chunks = array_chunk($allDataIds, 500);
                foreach ($chunks as $i => $chunk) {
                    $success = $this->kizeoApi->markAsUnread($formId, $chunk);
                    
                    if ($success) {
                        $totalMarked += count($chunk);
                        $io->comment(sprintf('  → Batch %d/%d : %d IDs marqués non lus ✓',
                            $i + 1, count($chunks), count($chunk)));
                    } else {
                        $totalErrors++;
                        $io->warning(sprintf('  → Batch %d/%d : échec', $i + 1, count($chunks)));
                    }

                    usleep(300000); // 300ms de pause entre chaque batch
                }

            } catch (\Exception $e) {
                $totalErrors++;
                $io->error("  → Erreur : " . $e->getMessage());
            }
        }

        $io->newLine();
        $io->success(sprintf('Terminé ! %d data marqués non lus, %d erreurs', $totalMarked, $totalErrors));

        return Command::SUCCESS;
    }
}