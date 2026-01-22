<?php

namespace App\Service\Photo;

use App\Entity\Photo;
use App\Service\Kizeo\KizeoApiService;
use Psr\Log\LoggerInterface;

/**
 * Service de stockage des photos
 * 
 * Arborescence: /{agence}/{id_contact}/{annee}/{visite}/{numero_equipement}_{type}.jpg
 */
class PhotoStorageService
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly LoggerInterface $logger,
        private readonly string $storagePath,
    ) {
    }

    /**
     * Télécharge et stocke une photo depuis Kizeo
     */
    public function downloadAndStore(Photo $photo): bool
    {
        if (!$photo->getKizeoFormId() || !$photo->getKizeoDataId() || !$photo->getKizeoMediaName()) {
            $this->logger->warning('Photo sans références Kizeo', ['id' => $photo->getId()]);
            return false;
        }

        // Télécharger depuis Kizeo
        $content = $this->kizeoApi->downloadMedia(
            $photo->getKizeoFormId(),
            $photo->getKizeoDataId(),
            $photo->getKizeoMediaName()
        );

        if (!$content) {
            $this->logger->error('Échec téléchargement photo', [
                'form_id' => $photo->getKizeoFormId(),
                'data_id' => $photo->getKizeoDataId(),
                'media' => $photo->getKizeoMediaName(),
            ]);
            return false;
        }

        // Créer le chemin de stockage
        $path = $this->buildStoragePath($photo);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sauvegarder
        $result = file_put_contents($path, $content);

        if ($result === false) {
            $this->logger->error('Échec sauvegarde photo', ['path' => $path]);
            return false;
        }

        $this->logger->info('Photo sauvegardée', [
            'path' => $path,
            'size' => strlen($content),
        ]);

        return true;
    }

    /**
     * Construit le chemin de stockage complet
     */
    public function buildStoragePath(Photo $photo): string
    {
        $filename = sprintf(
            '%s_%s.jpg',
            $photo->getNumeroEquipement() ?? 'general',
            $this->sanitizeFilename($photo->getKizeoMediaName() ?? 'photo')
        );

        return sprintf(
            '%s/%s/%d/%s/%s/%s',
            rtrim($this->storagePath, '/'),
            $photo->getCodeAgence(),
            $photo->getIdContact(),
            $photo->getAnnee(),
            $photo->getVisite(),
            $filename
        );
    }

    /**
     * Vérifie si une photo existe localement
     */
    public function exists(Photo $photo): bool
    {
        $path = $this->buildStoragePath($photo);
        return file_exists($path);
    }

    /**
     * Retourne le chemin relatif pour affichage web
     */
    public function getWebPath(Photo $photo): string
    {
        return sprintf(
            '/storage/%s/%d/%s/%s/%s_%s.jpg',
            $photo->getCodeAgence(),
            $photo->getIdContact(),
            $photo->getAnnee(),
            $photo->getVisite(),
            $photo->getNumeroEquipement() ?? 'general',
            $this->sanitizeFilename($photo->getKizeoMediaName() ?? 'photo')
        );
    }

    /**
     * Nettoie un nom de fichier
     */
    private function sanitizeFilename(string $filename): string
    {
        // Supprimer l'extension si présente
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Nettoyer les caractères spéciaux
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }
}
