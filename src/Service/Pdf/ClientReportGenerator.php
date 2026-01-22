<?php

namespace App\Service\Pdf;

use App\Service\Equipment\EquipmentFactory;
use App\Service\Photo\PhotoStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Génère les rapports clients PDF
 * 
 * Gère la génération avec ou sans photos pour éviter les erreurs mémoire
 */
class ClientReportGenerator
{
    private const MEMORY_LIMIT_WITH_PHOTOS = '512M';
    private const MEMORY_LIMIT_WITHOUT_PHOTOS = '256M';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly PhotoStorageService $photoStorage,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Génère le rapport client
     * 
     * @param array<string, mixed> $clientData
     * @return string Chemin du PDF généré
     */
    public function generate(
        string $agencyCode,
        int $idContact,
        array $clientData,
        string $annee,
        string $visite,
        bool $includePhotos = false
    ): string {
        // Adapter la mémoire selon le mode
        $memoryLimit = $includePhotos ? self::MEMORY_LIMIT_WITH_PHOTOS : self::MEMORY_LIMIT_WITHOUT_PHOTOS;
        ini_set('memory_limit', $memoryLimit);
        ini_set('max_execution_time', '300');

        $this->logger->info('Génération rapport client', [
            'agency' => $agencyCode,
            'id_contact' => $idContact,
            'with_photos' => $includePhotos,
        ]);

        // Récupérer les équipements
        $entityClass = $this->equipmentFactory->getEntityClassForAgency($agencyCode);
        $repo = $this->em->getRepository($entityClass);

        $contractEquipments = $repo->findContractEquipments($idContact, $visite, $annee);
        $offContractEquipments = $repo->findOffContractEquipments($idContact, $visite, $annee);

        // Compter par statut
        $statusCounts = $repo->countByStatus($idContact, $visite, $annee);

        // Préparer les données pour le template
        $data = [
            'client' => $clientData,
            'agency_code' => $agencyCode,
            'annee' => $annee,
            'visite' => $visite,
            'contract_equipments' => $contractEquipments,
            'offcontract_equipments' => $offContractEquipments,
            'status_counts' => $statusCounts,
            'include_photos' => $includePhotos,
            'generated_at' => new \DateTime(),
            'total_contract' => count($contractEquipments),
            'total_offcontract' => count($offContractEquipments),
        ];

        // Si photos incluses, charger les chemins
        if ($includePhotos) {
            $data['photos'] = $this->loadPhotosForEquipments(
                $agencyCode,
                $idContact,
                array_merge($contractEquipments, $offContractEquipments),
                $annee,
                $visite
            );
        }

        // Générer le HTML
        $html = $this->twig->render('pdf/client_report.html.twig', $data);

        // Générer le PDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Sauvegarder
        $outputPath = $this->buildOutputPath($agencyCode, $idContact, $clientData['raison_sociale'] ?? 'client', $annee, $visite, $includePhotos);
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $dompdf->output());

        $this->logger->info('Rapport client généré', [
            'path' => $outputPath,
            'equipments' => count($contractEquipments) + count($offContractEquipments),
        ]);

        return $outputPath;
    }

    /**
     * Charge les chemins des photos pour les équipements
     * 
     * @param array<object> $equipments
     * @return array<string, string>
     */
    private function loadPhotosForEquipments(
        string $agencyCode,
        int $idContact,
        array $equipments,
        string $annee,
        string $visite
    ): array {
        $photos = [];

        foreach ($equipments as $equip) {
            $numero = $equip->getNumeroEquipement();
            $basePath = sprintf(
                '%s/%s/%d/%s/%s/%s',
                $this->storagePath,
                $agencyCode,
                $idContact,
                $annee,
                $visite,
                $numero
            );

            // Chercher les photos existantes
            $possibleExtensions = ['jpg', 'jpeg', 'png'];
            foreach ($possibleExtensions as $ext) {
                $path = $basePath . '_photo.' . $ext;
                if (file_exists($path)) {
                    $photos[$numero] = $path;
                    break;
                }
            }
        }

        return $photos;
    }

    /**
     * Construit le chemin de sortie du PDF
     */
    private function buildOutputPath(
        string $agencyCode,
        int $idContact,
        string $clientName,
        string $annee,
        string $visite,
        bool $withPhotos
    ): string {
        $suffix = $withPhotos ? '_photos' : '';
        $filename = sprintf(
            'CR_%s_%s_%s%s.pdf',
            $this->sanitize($clientName),
            $annee,
            $visite,
            $suffix
        );

        return sprintf(
            '%s/reports/%s/%d/%s',
            $this->storagePath,
            $agencyCode,
            $idContact,
            $filename
        );
    }

    /**
     * Nettoie une chaîne pour un nom de fichier
     */
    private function sanitize(string $str): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', substr($str, 0, 30));
    }
}
