<?php

namespace App\Service\Kizeo;

use Psr\Log\LoggerInterface;

/**
 * Service de téléchargement des PDF techniciens depuis Kizeo
 * 
 * Structure de stockage:
 * /{agence}/{id_contact}/{annee}/{visite}/{client}-{date}-{visite}-{dataId}.pdf
 */
class KizeoPdfDownloader
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly LoggerInterface $kizeoLogger,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Télécharge le PDF technicien d'une visite
     */
    public function download(
        int $formId,
        int $dataId,
        string $agencyCode,
        int $idContact,
        string $clientName,
        string $annee,
        string $visite,
        string $dateVisite
    ): ?string {
        // Télécharger depuis Kizeo
        $content = $this->kizeoApi->downloadPdf($formId, $dataId);

        if (!$content) {
            $this->kizeoLogger->error('Échec téléchargement PDF', [
                'form_id' => $formId,
                'data_id' => $dataId,
            ]);
            return null;
        }

        // Construire le chemin de stockage
        $path = $this->buildPath($agencyCode, $idContact, $clientName, $annee, $visite, $dateVisite, $dataId);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sauvegarder
        $result = file_put_contents($path, $content);

        if ($result === false) {
            $this->kizeoLogger->error('Échec sauvegarde PDF', ['path' => $path]);
            return null;
        }

        $this->kizeoLogger->info('PDF technicien sauvegardé', [
            'path' => $path,
            'size' => strlen($content),
        ]);

        return $path;
    }

    /**
     * Construit le chemin de stockage du PDF
     */
    public function buildPath(
        string $agencyCode,
        int $idContact,
        string $clientName,
        string $annee,
        string $visite,
        string $dateVisite,
        int $dataId = 0
    ): string {
        $filename = sprintf(
            '%s-%s-%s-%d.pdf',
            $this->sanitizeFilename($clientName),
            $dateVisite,
            $visite,
            $dataId
        );

        return sprintf(
            '%s/%s/%d/%s/%s/%s',
            rtrim($this->storagePath, '/'),
            $agencyCode,
            $idContact,
            $annee,
            $visite,
            $filename
        );
    }

    /**
     * Retourne le chemin web pour un PDF technicien
     */
    public function getWebPath(
        string $agencyCode,
        int $idContact,
        string $clientName,
        string $annee,
        string $visite,
        string $dateVisite,
        int $dataId = 0
    ): string {
        $filename = sprintf(
            '%s-%s-%s-%d.pdf',
            $this->sanitizeFilename($clientName),
            $dateVisite,
            $visite,
            $dataId
        );

        return sprintf(
            '/pdf/%s/%d/%s/%s/%s',
            $agencyCode,
            $idContact,
            $annee,
            $visite,
            $filename
        );
    }

    /**
     * Vérifie si un PDF existe
     */
    public function exists(
        string $agencyCode,
        int $idContact,
        string $clientName,
        string $annee,
        string $visite,
        string $dateVisite,
        int $dataId = 0
    ): bool {
        $path = $this->buildPath($agencyCode, $idContact, $clientName, $annee, $visite, $dateVisite, $dataId);
        return file_exists($path);
    }

    /**
     * Nettoie un nom pour l'utiliser dans un fichier
     */
    private function sanitizeFilename(string $name): string
    {
        // Remplacer les caractères spéciaux
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        // Limiter la longueur
        return substr($name, 0, 50);
    }
}