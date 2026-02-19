<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class ContratPdfService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly SluggerInterface $slugger,
    ) {}

    /**
     * Upload un PDF de contrat.
     * Stockage : storage/pdf-contrat/{code_agence}/{id_contact}/contrat_{numero}_{timestamp}.pdf
     *
     * @return string Le chemin relatif stocké en BDD (depuis storage/)
     */
    public function uploadContratPdf(
        string $agencyCode,
        string $idContact,
        int $numeroContrat,
        UploadedFile $file,
    ): string {
        $directory = sprintf(
            '%s/storage/pdf-contrat/%s/%s',
            $this->projectDir,
            strtoupper($agencyCode),
            $idContact
        );

        $filename = sprintf(
            'contrat_%d_%s.pdf',
            $numeroContrat,
            date('Ymd_His')
        );

        return $this->doUpload($file, $directory, $filename, 'contrat');
    }

    /**
     * Upload un PDF d'avenant.
     * Stockage : storage/pdf-avenant-contrat/{code_agence}/{id_contact}/avenant_{numero}_{timestamp}.pdf
     *
     * @return string Le chemin relatif stocké en BDD
     */
    public function uploadAvenantPdf(
        string $agencyCode,
        string $idContact,
        string $numeroAvenant,
        UploadedFile $file,
    ): string {
        $directory = sprintf(
            '%s/storage/pdf-avenant-contrat/%s/%s',
            $this->projectDir,
            strtoupper($agencyCode),
            $idContact
        );

        $filename = sprintf(
            'avenant_%s_%s.pdf',
            $this->slugger->slug($numeroAvenant)->lower(),
            date('Ymd_His')
        );

        return $this->doUpload($file, $directory, $filename, 'avenant');
    }

    /**
     * Supprime un fichier PDF.
     */
    public function deleteFile(string $relativePath): bool
    {
        $fullPath = $this->projectDir . '/storage/' . $relativePath;

        if (!file_exists($fullPath)) {
            $this->logger->warning('[ContratPdf] Fichier introuvable pour suppression.', [
                'path' => $relativePath,
            ]);
            return false;
        }

        if (unlink($fullPath)) {
            $this->logger->info('[ContratPdf] Fichier supprimé.', ['path' => $relativePath]);
            return true;
        }

        return false;
    }

    /**
     * Retourne le chemin absolu d'un fichier PDF pour le servir via un controller.
     */
    public function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/storage/' . $relativePath;
    }

    // -------------------------------------------------------

    private function doUpload(
        UploadedFile $file,
        string $directory,
        string $filename,
        string $type,
    ): string {
        // Créer le répertoire si nécessaire
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true)) {
                $this->logger->error("[ContratPdf] Impossible de créer le répertoire.", [
                    'directory' => $directory,
                ]);
                throw new \RuntimeException("Impossible de créer le répertoire de stockage.");
            }
        }

        try {
            $file->move($directory, $filename);
        } catch (FileException $e) {
            $this->logger->error("[ContratPdf] Échec de l'upload {$type}.", [
                'error' => $e->getMessage(),
                'filename' => $filename,
            ]);
            throw new \RuntimeException("Échec de l'upload du fichier PDF.");
        }

        // Chemin relatif depuis storage/ pour stockage en BDD
        $relativePath = str_replace(
            $this->projectDir . '/storage/',
            '',
            $directory . '/' . $filename
        );

        $this->logger->info("[ContratPdf] Upload {$type} réussi.", [
            'path' => $relativePath,
            'size' => filesize($directory . '/' . $filename),
        ]);

        return $relativePath;
    }
}