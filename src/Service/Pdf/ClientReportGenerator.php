<?php

namespace App\Service\Pdf;

use App\Service\Equipment\EquipmentFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Génère les rapports PDF "Compte Rendu Client"
 * 
 * Produit un PDF avec :
 * - Page de garde (infos client, résumé, tableaux statuts)
 * - Pages équipements (4 par page, photo + infos + logo statut)
 * - Header/footer = images par agence (chemins absolus)
 * - Logos statut = images PNG (chemins absolus)
 * 
 * Photos résolues par pattern :
 *   - Contrat : {numero}_2_{data_id}.jpg
 *   - Hors contrat : {numero}_compte_rendu_{data_id}.jpg
 *   - Chemin : storage/img/{agency}/{id_contact}/{annee}/{visite}/
 * 
 * Mapping statuts : Margaux V5
 * 
 * SESSION 10/02/2026 soir : Fix header/footer (chemins absolus au lieu de base64)
 *                           + 4 équipements par page
 */
class ClientReportGenerator
{
    private const MEMORY_LIMIT_WITH_PHOTOS = '1G';
    private const MEMORY_LIMIT_WITHOUT_PHOTOS = '512M';

    /**
     * Chemin vers les images header/footer par agence (dans storage, hors public)
     * Convention de nommage :
     *   entete_{code_agence}.png   ou  entete_{code_agence}.jpg
     *   pied_de_page_{code_agence}.png  ou  pied_de_page_{code_agence}.jpg
     */
    private const BRANDING_PATH = 'storage/img/pdf-branding';

    /**
     * Chemin vers les logos de statut (dans public)
     * Fichiers : vert.png, orange.png, rouge.png, noir.png, arret.png, inaccessible.png
     */
    private const STATUS_LOGOS_PATH = 'public/img/logos-statut';

    /**
     * Mapping statut → catégorie de comptage SOUS CONTRAT (A→G)
     */
    private const STATUS_MAP_CONTRACT = [
        'A' => 'bon_etat',
        'B' => 'travaux_preventifs',
        'C' => 'travaux_curatifs',
        'D' => 'inaccessible',
        'E' => 'a_l_arret',
        'F' => 'mis_a_l_arret',
        'G' => 'non_present',
    ];

    /**
     * Mapping statut → catégorie de comptage HORS CONTRAT (A→E)
     */
    private const STATUS_MAP_OFFCONTRACT = [
        'A' => 'bon_etat',
        'B' => 'travaux_preventifs',
        'C' => 'travaux_curatifs',
        'D' => 'a_l_arret',
        'E' => 'mis_a_l_arret',
    ];

    /**
     * Mapping agence → infos complémentaires (SIREN, etc.)
     * À terme, ces données seront en BDD dans la table agencies
     */
    private const AGENCY_EXTRA_INFO = [
        'S10'  => ['siren' => '540 039 534', 'ape' => '4332 B'],
        'S40'  => ['siren' => '792 630 774', 'ape' => '4332 B'],
        'S50'  => ['siren' => '528 372 501', 'ape' => '4332 B'],
        'S60'  => ['siren' => '528 421 688', 'ape' => '4332 B'],
        'S70'  => ['siren' => '528 421 415', 'ape' => '4332 B'],
        'S80'  => ['siren' => '802 434 241', 'ape' => '4332 B'],
        'S100' => ['siren' => '453 427 932', 'ape' => '4332 B'],
        'S120' => ['siren' => '799 909 957', 'ape' => '4332 B'],
        'S130' => ['siren' => '403 651 151', 'ape' => '4332 B'],
        'S140' => ['siren' => '842 128 944', 'ape' => '4332 B'],
        'S150' => ['siren' => '438 296 386', 'ape' => '4332 B'],
        'S160' => ['siren' => '929 596 971', 'ape' => '4332 B'],
        'S170' => ['siren' => '929 643 559', 'ape' => '4332 B'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $storagePath,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Génère le rapport client PDF
     */
    public function generate(
        string $agencyCode,
        int $idContact,
        array $clientData,
        string $annee,
        string $visite,
        bool $includePhotos = true
    ): string {
        $memoryLimit = $includePhotos ? self::MEMORY_LIMIT_WITH_PHOTOS : self::MEMORY_LIMIT_WITHOUT_PHOTOS;
        ini_set('memory_limit', $memoryLimit);
        ini_set('max_execution_time', '300');

        $this->logger->info('Début génération PDF client', [
            'agency' => $agencyCode,
            'id_contact' => $idContact,
            'annee' => $annee,
            'visite' => $visite,
            'with_photos' => $includePhotos,
        ]);

        // 1. Charger les infos agence
        $agencyData = $this->loadAgencyData($agencyCode);

        // 2. Récupérer les équipements (DBAL pour perf)
        $tableName = 'equipement_' . strtolower($agencyCode);
        $contractEquipments = $this->fetchEquipments($tableName, $idContact, $annee, $visite, false);
        $offContractEquipments = $this->fetchEquipments($tableName, $idContact, $annee, $visite, true);

        // 3. Compter par statut (mapping DIFFÉRENT contrat / HC)
        $contractStatusCounts = $this->countByStatusCategory($contractEquipments, false);
        $offContractStatusCounts = $this->countByStatusCategory($offContractEquipments, true);

        // 4. Déterminer la date de visite (la plus récente)
        $dateVisite = $this->findVisitDate($contractEquipments, $offContractEquipments);

        // 5. Résoudre les photos
        $photos = [];
        if ($includePhotos) {
            $photos = $this->resolveAllPhotos(
                $agencyCode,
                $idContact,
                $annee,
                $visite,
                $contractEquipments,
                $offContractEquipments
            );
        }

        // 6. Charger les images branding (chemins absolus) + logos statut
        $headerImage = $this->loadHeaderImage($agencyCode);
        $footerImage = $this->loadFooterImage($agencyCode);
        $statusLogos = $this->loadStatusLogos();

        // 7. Préparer les données template
        $data = [
            'client'                     => $clientData,
            'agency'                     => $agencyData,
            'agency_code'                => $agencyCode,
            'annee'                      => $annee,
            'visite'                     => $visite,
            'date_visite'                => $dateVisite,
            'contract_equipments'        => $contractEquipments,
            'offcontract_equipments'     => $offContractEquipments,
            'contract_status_counts'     => $contractStatusCounts,
            'offcontract_status_counts'  => $offContractStatusCounts,
            'total_contract'             => count($contractEquipments),
            'total_offcontract'          => count($offContractEquipments),
            'include_photos'             => $includePhotos,
            'photos'                     => $photos,
            'generated_at'               => new \DateTime(),
            'header_image'               => $headerImage,
            'footer_image'               => $footerImage,
            'status_logos'               => $statusLogos,
        ];

        // 8. Render HTML
        $html = $this->twig->render('pdf/client_report.html.twig', $data);

        // 9. Générer le PDF via DomPDF
        $pdf = $this->renderPdf($html);

        // 10. Sauvegarder
        $outputPath = $this->buildOutputPath($agencyCode, $idContact, $clientData, $annee, $visite, $includePhotos);
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outputPath, $pdf);

        $this->logger->info('PDF client généré avec succès', [
            'path' => $outputPath,
            'contract' => count($contractEquipments),
            'hors_contrat' => count($offContractEquipments),
            'photos' => count($photos),
            'size' => strlen($pdf),
        ]);

        return $outputPath;
    }

    // =========================================================================
    // Données agence
    // =========================================================================

    private function loadAgencyData(string $agencyCode): array
    {
        $sql = "SELECT code, nom, adresse, code_postal, ville, telephone, email 
                FROM agencies 
                WHERE code = :code 
                LIMIT 1";

        $agency = $this->connection->fetchAssociative($sql, ['code' => $agencyCode]);

        if (!$agency) {
            $agency = [
                'code' => $agencyCode,
                'nom' => $agencyCode,
                'adresse' => '',
                'code_postal' => '',
                'ville' => '',
                'telephone' => '',
                'email' => '',
            ];
        }

        $extra = self::AGENCY_EXTRA_INFO[$agencyCode] ?? ['siren' => '', 'ape' => '4332 B'];
        $agency['siren'] = $extra['siren'];
        $agency['ape'] = $extra['ape'];

        return $agency;
    }

    // =========================================================================
    // Équipements
    // =========================================================================

    private function fetchEquipments(
        string $tableName,
        int $idContact,
        string $annee,
        string $visite,
        bool $horsContrat
    ): array {
        $sql = "SELECT 
                    id,
                    numero_equipement,
                    libelle_equipement,
                    statut_equipement,
                    etat_equipement,
                    marque,
                    mise_en_service,
                    repere_site_client,
                    anomalies,
                    observations,
                    mode_fonctionnement,
                    hauteur,
                    largeur,
                    longueur,
                    numero_serie,
                    date_derniere_visite,
                    is_hors_contrat,
                    kizeo_data_id
                FROM {$tableName}
                WHERE id_contact = :id_contact
                  AND annee = :annee
                  AND visite = :visite
                  AND is_hors_contrat = :hc
                  AND is_archive = 0
                ORDER BY numero_equipement ASC";

        return $this->connection->fetchAllAssociative($sql, [
            'id_contact' => $idContact,
            'annee' => $annee,
            'visite' => $visite,
            'hc' => $horsContrat ? 1 : 0,
        ]);
    }

    // =========================================================================
    // Comptage par statut (Mapping Margaux V5)
    // =========================================================================

    private function countByStatusCategory(array $equipments, bool $isOffContract = false): array
    {
        $statusMap = $isOffContract ? self::STATUS_MAP_OFFCONTRACT : self::STATUS_MAP_CONTRACT;

        $counts = [];
        foreach (array_unique(array_values($statusMap)) as $category) {
            $counts[$category] = 0;
        }

        foreach ($equipments as $equip) {
            $statut = strtoupper(trim($equip['statut_equipement'] ?? ''));
            $category = $statusMap[$statut] ?? null;

            if ($category !== null) {
                $counts[$category]++;
            }
        }

        return $counts;
    }

    // =========================================================================
    // Date de visite
    // =========================================================================

    private function findVisitDate(array $contractEquipments, array $offContractEquipments): ?\DateTime
    {
        $allEquipments = array_merge($contractEquipments, $offContractEquipments);
        $latestDate = null;

        foreach ($allEquipments as $equip) {
            $dateStr = $equip['date_derniere_visite'] ?? null;
            if ($dateStr) {
                try {
                    $date = new \DateTime($dateStr);
                    if ($latestDate === null || $date > $latestDate) {
                        $latestDate = $date;
                    }
                } catch (\Exception $e) {
                    // Ignorer les dates invalides
                }
            }
        }

        return $latestDate;
    }

    // =========================================================================
    // Résolution des photos
    // =========================================================================

    private function resolveAllPhotos(
        string $agencyCode,
        int $idContact,
        string $annee,
        string $visite,
        array $contractEquipments,
        array $offContractEquipments
    ): array {
        $photos = [];
        $basePath = sprintf(
            '%s/img/%s/%d/%s/%s',
            rtrim($this->storagePath, '/'),
            $agencyCode,
            $idContact,
            $annee,
            $visite
        );

        foreach ($contractEquipments as $equip) {
            $numero = $equip['numero_equipement'];
            $dataId = $equip['kizeo_data_id'] ?? null;
            $photo = $this->findPhoto($basePath, $numero, '_2_', $dataId);
            if ($photo) {
                $photos[$numero] = $photo;
            }
        }

        foreach ($offContractEquipments as $equip) {
            $numero = $equip['numero_equipement'];
            $dataId = $equip['kizeo_data_id'] ?? null;
            $photo = $this->findPhoto($basePath, $numero, '_compte_rendu_', $dataId);
            if ($photo) {
                $photos[$numero] = $photo;
            }
        }

        $this->logger->info('Photos résolues', [
            'total_found' => count($photos),
            'base_path' => $basePath,
        ]);

        return $photos;
    }

    private function findPhoto(string $basePath, string $numero, string $suffix, ?int $dataId): ?string
    {
        if ($dataId !== null) {
            $exactPath = sprintf('%s/%s%s%d.jpg', $basePath, $numero, $suffix, $dataId);
            if (file_exists($exactPath)) {
                return $exactPath;
            }
        }

        $pattern = sprintf('%s/%s%s*.jpg', $basePath, $numero, $suffix);
        $matches = glob($pattern);

        if (!empty($matches)) {
            return $matches[0];
        }

        foreach (['jpeg', 'png'] as $ext) {
            $pattern = sprintf('%s/%s%s*.%s', $basePath, $numero, $suffix, $ext);
            $matches = glob($pattern);
            if (!empty($matches)) {
                return $matches[0];
            }
        }

        return null;
    }

    // =========================================================================
    // Images branding (header/footer par agence) — CHEMINS ABSOLUS
    // =========================================================================
    // 
    // FIX SESSION 10/02 soir : les data URI base64 ne fonctionnaient pas avec 
    // DomPDF pour les images de grande taille. On utilise maintenant les chemins  
    // absolus du filesystem, que DomPDF résout nativement via le chroot.
    // C'est la même approche que pour les photos d'équipements (qui marchent).
    // =========================================================================

    /**
     * Retourne le chemin absolu de l'image d'entête pour une agence.
     * DomPDF résoudra ce chemin via le chroot configuré.
     */
    private function loadHeaderImage(string $agencyCode): ?string
    {
        $basePath = $this->projectDir . '/' . self::BRANDING_PATH;
        $baseName = 'entete_' . $agencyCode;

        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $filePath = $basePath . '/' . $baseName . '.' . $ext;
            
            $realPath = realpath($filePath);
            
            if ($realPath && file_exists($realPath)) {
                // Normaliser en forward slashes (Windows → compatible DomPDF canvas)
                $normalized = str_replace('\\', '/', $realPath);
                $this->logger->info('Header image trouvée', ['path' => $normalized]);
                return $normalized;
            }
            
            if (file_exists($filePath)) {
                $normalized = str_replace('\\', '/', $filePath);
                $this->logger->info('Header image trouvée (sans realpath)', ['path' => $normalized]);
                return $normalized;
            }
        }

        $this->logger->warning('Aucune image d\'entête trouvée', [
            'agency' => $agencyCode,
            'searched_in' => $basePath,
            'expected' => $baseName . '.{png,jpg}',
        ]);
        return null;
    }

    /**
     * Retourne le chemin absolu de l'image de pied de page pour une agence.
     */
    private function loadFooterImage(string $agencyCode): ?string
    {
        $basePath = $this->projectDir . '/' . self::BRANDING_PATH;
        $baseName = 'pied_de_page_' . $agencyCode;

        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $filePath = $basePath . '/' . $baseName . '.' . $ext;
            
            $realPath = realpath($filePath);
            
            if ($realPath && file_exists($realPath)) {
                $normalized = str_replace('\\', '/', $realPath);
                $this->logger->info('Footer image trouvée', ['path' => $normalized]);
                return $normalized;
            }
            
            if (file_exists($filePath)) {
                $normalized = str_replace('\\', '/', $filePath);
                $this->logger->info('Footer image trouvée (sans realpath)', ['path' => $normalized]);
                return $normalized;
            }
        }

        $this->logger->warning('Aucune image de pied de page trouvée', [
            'agency' => $agencyCode,
            'searched_in' => $basePath,
            'expected' => $baseName . '.{png,jpg}',
        ]);
        return null;
    }

    /**
     * Charge les chemins absolus des logos de statut.
     */
    private function loadStatusLogos(): array
    {
        $logosPath = $this->projectDir . '/' . self::STATUS_LOGOS_PATH;

        $logoFiles = [
            'vert'          => 'vert.png',
            'orange'        => 'orange.png',
            'rouge'         => 'rouge.png',
            'noir'          => 'noir.png',
            'arret'         => 'arret.png',
            'inaccessible'  => 'inaccessible.png',
        ];

        $logos = [];
        foreach ($logoFiles as $key => $filename) {
            $filePath = $logosPath . '/' . $filename;
            $realPath = realpath($filePath);
            
            if ($realPath && file_exists($realPath)) {
                $logos[$key] = str_replace('\\', '/', $realPath);
            } elseif (file_exists($filePath)) {
                $logos[$key] = str_replace('\\', '/', $filePath);
            } else {
                $logos[$key] = null;
                $this->logger->warning('Logo statut manquant', ['file' => $filePath]);
            }
        }

        return $logos;
    }

    // =========================================================================
    // Génération PDF (DomPDF)
    // =========================================================================

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Arial');
        $options->set('isFontSubsettingEnabled', true);
        // IMPORTANT : isPhpEnabled = true pour les header/footer via canvas
        $options->set('isPhpEnabled', true);
        $options->set('debugKeepTemp', false);

        // Chroot : autoriser storage/ ET public/ ET le projectDir lui-même
        $chroot = [
            realpath($this->projectDir) ?: $this->projectDir,
            realpath($this->storagePath) ?: $this->storagePath,
            realpath($this->projectDir . '/public') ?: $this->projectDir . '/public',
        ];
        
        $chroot = array_values(array_unique(array_filter($chroot)));
        $options->set('chroot', $chroot);

        $this->logger->debug('DomPDF chroot configuré', ['chroot' => $chroot]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    // =========================================================================
    // Utilitaires
    // =========================================================================

    private function buildOutputPath(
        string $agencyCode,
        int $idContact,
        array $clientData,
        string $annee,
        string $visite,
        bool $withPhotos
    ): string {
        $clientName = $clientData['raison_sociale'] ?? 'client';
        $suffix = $withPhotos ? '_photos' : '';
        $filename = sprintf(
            'CR_%s_%s_%s%s.pdf',
            $this->sanitizeFilename($clientName),
            $annee,
            $visite,
            $suffix
        );

        return sprintf(
            '%s/pdf-client/%s/%d/%s',
            rtrim($this->storagePath, '/'),
            $agencyCode,
            $idContact,
            $filename
        );
    }

    private function sanitizeFilename(string $str): string
    {
        $str = transliterator_transliterate('Any-Latin; Latin-ASCII', $str) ?: $str;
        $str = preg_replace('/[^a-zA-Z0-9_-]/', '_', $str) ?? $str;
        return substr($str, 0, 40);
    }
}