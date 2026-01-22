<?php

namespace App\Service\Kizeo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Service d'appels à l'API Kizeo Forms
 * 
 * Documentation API: https://www.kizeoforms.com/doc/swagger/v3/
 */
class KizeoApiService
{
    private const TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $kizeoLogger,
        private readonly string $apiUrl,
        private readonly string $apiToken,
    ) {
    }

    /**
     * Récupère les formulaires non lus pour un form donné
     * 
     * @return array<mixed>
     */
    public function getUnreadForms(int $formId, int $limit = 50): array
    {
        $endpoint = sprintf('/forms/%d/data/unread/read/%d', $formId, $limit);
        
        try {
            $response = $this->request('GET', $endpoint);
            
            if (!isset($response['data'])) {
                $this->kizeoLogger->warning('Réponse API sans données', [
                    'form_id' => $formId,
                    'response' => $response,
                ]);
                return [];
            }
            
            return $response['data'];
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération formulaires', [
                'form_id' => $formId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Récupère les détails d'une soumission spécifique
     * 
     * @return array<mixed>|null
     */
    public function getFormData(int $formId, int $dataId): ?array
    {
        $endpoint = sprintf('/forms/%d/data/%d', $formId, $dataId);
        
        try {
            $response = $this->request('GET', $endpoint);
            return $response['data'] ?? null;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération données formulaire', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Marque un formulaire comme lu
     * CRITIQUE: Sans cette étape, les CRON créent des doublons
     */
    public function markAsRead(int $formId, int $dataId): bool
    {
        $endpoint = sprintf('/forms/%d/data/%d/mark_as_read', $formId, $dataId);
        
        try {
            $this->request('POST', $endpoint);
            
            $this->kizeoLogger->info('Formulaire marqué comme lu', [
                'form_id' => $formId,
                'data_id' => $dataId,
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur marquage formulaire lu', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Télécharge un média (photo) depuis Kizeo
     * 
     * @return string|null Contenu binaire de l'image
     */
    public function downloadMedia(int $formId, int $dataId, string $mediaName): ?string
    {
        $endpoint = sprintf('/forms/%d/data/%d/medias/%s', $formId, $dataId, $mediaName);
        
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                ],
                'timeout' => 60, // Plus long pour les médias
            ]);
            
            if ($response->getStatusCode() === 200) {
                return $response->getContent();
            }
            
            return null;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur téléchargement média', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'media_name' => $mediaName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Télécharge le PDF technicien d'une soumission
     * 
     * @return string|null Contenu binaire du PDF
     */
    public function downloadPdf(int $formId, int $dataId): ?string
    {
        $endpoint = sprintf('/forms/%d/data/%d/pdf', $formId, $dataId);
        
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                ],
                'timeout' => 60,
            ]);
            
            if ($response->getStatusCode() === 200) {
                return $response->getContent();
            }
            
            return null;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur téléchargement PDF', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Récupère une liste externe Kizeo (clients ou équipements)
     * 
     * Endpoint: GET /rest/v3/lists/{list_id}
     * 
     * @param int $listId ID de la liste Kizeo (kizeo_list_id ou kizeo_external_list_id)
     * @return array{
     *     status: string,
     *     list: array{
     *         id: string,
     *         name: string,
     *         class: string,
     *         update_time: string,
     *         is_advanced: bool,
     *         items: array<string>
     *     }
     * }|null
     */
    public function getList(int $listId): ?array
    {
        $endpoint = sprintf('/lists/%d', $listId);
        
        try {
            $response = $this->request('GET', $endpoint);
            
            $this->kizeoLogger->debug('Liste Kizeo récupérée', [
                'list_id' => $listId,
                'items_count' => count($response['list']['items'] ?? []),
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération liste Kizeo', [
                'list_id' => $listId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Met à jour une liste externe Kizeo
     * ATTENTION: Écrase la liste existante
     * 
     * @param array<mixed> $items
     */
    public function updateList(int $listId, array $items): bool
    {
        $endpoint = sprintf('/lists/%d', $listId);
        
        try {
            $this->request('PUT', $endpoint, [
                'json' => ['items' => $items],
            ]);
            
            $this->kizeoLogger->info('Liste Kizeo mise à jour', [
                'list_id' => $listId,
                'items_count' => count($items),
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur mise à jour liste', [
                'list_id' => $listId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Récupère tous les formulaires disponibles
     * 
     * @return array<mixed>
     */
    public function getAllForms(): array
    {
        try {
            $response = $this->request('GET', '/forms');
            return $response['forms'] ?? [];
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération liste formulaires', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Effectue une requête à l'API Kizeo
     * 
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $defaultOptions = [
            'headers' => [
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
        ];
        
        $options = array_merge_recursive($defaultOptions, $options);
        
        $this->kizeoLogger->debug('Requête API Kizeo', [
            'method' => $method,
            'endpoint' => $endpoint,
        ]);
        
        $response = $this->httpClient->request($method, $this->apiUrl . $endpoint, $options);
        
        $statusCode = $response->getStatusCode();
        
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Erreur API Kizeo: %d - %s',
                $statusCode,
                $response->getContent(false)
            ));
        }
        
        $content = $response->getContent();
        
        if (empty($content)) {
            return [];
        }
        
        return json_decode($content, true) ?? [];
    }
}