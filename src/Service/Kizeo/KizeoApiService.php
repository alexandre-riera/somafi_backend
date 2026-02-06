<?php

namespace App\Service\Kizeo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Service d'appels à l'API Kizeo Forms
 * 
 * Documentation API: https://www.kizeoforms.com/doc/swagger/v3/
 * 
 * IMPORTANT:
 * - Utiliser /data/unread/read/{limit} et NON /data/advanced (timeout fréquent)
 * - markAsRead() utilise markasreadbyaction avec body JSON (corrigé 27/01/2026)
 */
class KizeoApiService
{
    private const TIMEOUT = 30;
    private const TIMEOUT_MEDIA = 60;  // Plus long pour les téléchargements
    private const TIMEOUT_PDF = 90;    // PDF peuvent être volumineux

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $kizeoLogger,
        private readonly string $apiUrl,
        private readonly string $apiToken,
    ) {
    }

    // =========================================================================
    // RÉCUPÉRATION DES FORMULAIRES
    // =========================================================================

    /**
     * Récupère les formulaires non lus pour un form donné
     * 
     * ⚠️ IMPORTANT: Utilise /unread/read/{limit} et NON /advanced (timeout)
     * 
     * @return array<mixed>
     */
    public function getUnreadForms(int $formId, int $limit = 10): array
    {
        // Limite à 10 pour éviter OOM sur O2switch mutualisé
        $limit = min($limit, 50);
        
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
            
            $this->kizeoLogger->info('Formulaires non lus récupérés', [
                'form_id' => $formId,
                'count' => count($response['data']),
            ]);
            
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

    // =========================================================================
    // MARQUAGE COMME LU
    // =========================================================================

    /**
     * Marque UN formulaire comme lu
     * 
     * ⚠️ CORRIGÉ 27/01/2026:
     * - Ancien endpoint (FAUX): /forms/{formId}/data/{dataId}/mark_as_read
     * - Nouvel endpoint (CORRECT): /forms/{formId}/markasreadbyaction/read
     * - Méthode: POST avec body JSON { "data_ids": ["dataId"] }
     * 
     * CRITIQUE: Sans cette étape, les CRON récupèrent les mêmes CR et créent des doublons !
     */
    public function markAsRead(int $formId, int $dataId): bool
    {
        $endpoint = sprintf('/forms/%d/markasreadbyaction/read', $formId);
        
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data_ids' => [(string) $dataId]  // ← Tableau avec 1 ID (string)
                ],
                'timeout' => self::TIMEOUT,
            ]);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                $this->kizeoLogger->info('Formulaire marqué comme lu', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                ]);
                return true;
            }
            
            $this->kizeoLogger->warning('Marquage lu - code inattendu', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'status_code' => $statusCode,
            ]);
            return false;
            
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
     * Marque PLUSIEURS formulaires comme lus en une seule requête
     * 
     * Utile pour optimiser les appels API lors du traitement par batch
     * 
     * @param array<int> $dataIds Liste des data_id à marquer
     */
    public function markMultipleAsRead(int $formId, array $dataIds): bool
    {
        if (empty($dataIds)) {
            return true;
        }
        
        $endpoint = sprintf('/forms/%d/markasreadbyaction/read', $formId);
        
        try {
            // Convertir tous les IDs en strings
            $stringIds = array_map('strval', $dataIds);
            
            $response = $this->httpClient->request('POST', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data_ids' => $stringIds
                ],
                'timeout' => self::TIMEOUT,
            ]);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                $this->kizeoLogger->info('Formulaires marqués comme lus', [
                    'form_id' => $formId,
                    'count' => count($dataIds),
                    'data_ids' => $dataIds,
                ]);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur marquage multiple lu', [
                'form_id' => $formId,
                'data_ids' => $dataIds,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // TÉLÉCHARGEMENT MÉDIAS (PHOTOS)
    // =========================================================================

    /**
     * Télécharge un média (photo) depuis Kizeo
     * 
     * @return string|null Contenu binaire de l'image
     */
    public function downloadMedia(int $formId, int $dataId, string $mediaName): ?string
    {
        $endpoint = sprintf('/forms/%d/data/%d/medias/%s', $formId, $dataId, urlencode($mediaName));
        
        try {
            $response = $this->httpClient->request('GET', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                ],
                'timeout' => self::TIMEOUT_MEDIA,
            ]);
            
            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();
                
                $this->kizeoLogger->debug('Média téléchargé', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'media_name' => $mediaName,
                    'size' => strlen($content),
                ]);
                
                return $content;
            }
            
            $this->kizeoLogger->warning('Téléchargement média - code inattendu', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'media_name' => $mediaName,
                'status_code' => $response->getStatusCode(),
            ]);
            
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

    // =========================================================================
    // TÉLÉCHARGEMENT PDF
    // =========================================================================

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
                'timeout' => self::TIMEOUT_PDF,
            ]);
            
            if ($response->getStatusCode() === 200) {
                $content = $response->getContent();
                
                $this->kizeoLogger->debug('PDF téléchargé', [
                    'form_id' => $formId,
                    'data_id' => $dataId,
                    'size' => strlen($content),
                ]);
                
                return $content;
            }
            
            $this->kizeoLogger->warning('Téléchargement PDF - code inattendu', [
                'form_id' => $formId,
                'data_id' => $dataId,
                'status_code' => $response->getStatusCode(),
            ]);
            
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

    // =========================================================================
    // GESTION DES LISTES
    // =========================================================================

    /**
     * Récupère une liste Kizeo (clients ou équipements)
     * 
     * @return array<mixed> Structure complète: ['list' => ['items' => [...]]]
     */
    public function getList(int $listId): array
    {
        $endpoint = sprintf('/lists/%d', $listId);
        
        try {
            $response = $this->request('GET', $endpoint);
            return $response; // Retourne la structure complète pour KizeoClientService
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération liste', [
                'list_id' => $listId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Met à jour une liste externe Kizeo
     * 
     * ⚠️ ATTENTION: Écrase la liste existante !
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

    // =========================================================================
    // UTILITAIRES - FORMULAIRES
    // =========================================================================

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
     * Récupère uniquement les formulaires de MAINTENANCE
     * 
     * Filtre les formulaires par la propriété "class" = "MAINTENANCE"
     * Ce sont les formulaires de visite technicien des agences (V4/V5).
     * 
     * @return array<mixed> Liste des formulaires de maintenance avec leur id, name, class
     */
    public function getMaintenanceForms(): array
    {
        $allForms = $this->getAllForms();
        
        $maintenanceForms = array_filter($allForms, function (array $form): bool {
            return isset($form['class']) && strtoupper($form['class']) === 'MAINTENANCE';
        });

        $this->kizeoLogger->info('Formulaires MAINTENANCE récupérés', [
            'total_forms' => count($allForms),
            'maintenance_forms' => count($maintenanceForms),
        ]);

        return array_values($maintenanceForms); // Reset array keys
    }

    /**
     * Récupère les formulaires par classe
     * 
     * @param string $class Nom de la classe (MAINTENANCE, INTERVENTION, etc.)
     * @return array<mixed>
     */
    public function getFormsByClass(string $class): array
    {
        $allForms = $this->getAllForms();
        
        $filteredForms = array_filter($allForms, function (array $form) use ($class): bool {
            return isset($form['class']) && strtoupper($form['class']) === strtoupper($class);
        });

        return array_values($filteredForms);
    }

    /**
     * Trouve un formulaire de maintenance par son nom (recherche partielle)
     * 
     * Utile pour trouver le form_id d'une agence par son nom
     * Ex: findMaintenanceFormByName('MONTPELLIER') → retourne le form V5 - MONTPELLIER
     * 
     * @return array<mixed>|null Le formulaire trouvé ou null
     */
    public function findMaintenanceFormByName(string $searchName): ?array
    {
        $maintenanceForms = $this->getMaintenanceForms();
        
        $searchName = strtoupper($searchName);
        
        foreach ($maintenanceForms as $form) {
            if (isset($form['name']) && str_contains(strtoupper($form['name']), $searchName)) {
                return $form;
            }
        }

        return null;
    }

    /**
     * Retourne un mapping nom d'agence → form_id pour les formulaires MAINTENANCE
     * 
     * Utile pour initialiser/vérifier les form_id dans la table agencies
     * 
     * @return array<string, int> [nom_formulaire => form_id]
     */
    public function getMaintenanceFormsMapping(): array
    {
        $maintenanceForms = $this->getMaintenanceForms();
        
        $mapping = [];
        foreach ($maintenanceForms as $form) {
            if (isset($form['id'], $form['name'])) {
                $mapping[$form['name']] = (int) $form['id'];
            }
        }

        return $mapping;
    }

    // =========================================================================
    // UTILITAIRES - MONITORING
    // =========================================================================

    /**
     * Compte le nombre de formulaires non lus pour un form donné
     * 
     * Utile pour monitoring sans récupérer toutes les données
     */
    public function countUnreadForms(int $formId): int
    {
        $endpoint = sprintf('/forms/%d/data/unread/count', $formId);
        
        try {
            $response = $this->request('GET', $endpoint);
            return $response['count'] ?? 0;
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur comptage formulaires non lus', [
                'form_id' => $formId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Vérifie la connectivité à l'API Kizeo
     */
    public function ping(): bool
    {
        try {
            $response = $this->request('GET', '/forms');
            return isset($response['forms']);
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Ping API Kizeo échoué', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // MARQUAGE COMME NON LU (RESET)
    // =========================================================================

    /**
     * Récupère TOUS les data_ids d'un formulaire (lus + non lus)
     * 
     * @return array<int> Liste des data_ids
     */
    public function getAllDataIdsForForm(int $formId): array
    {
        $endpoint = sprintf('/forms/%d/data/all', $formId);
        
        try {
            $response = $this->request('GET', $endpoint);
            
            // L'API retourne { "data": [ id1, id2, ... ] }
            $dataIds = $response['data'] ?? [];
            
            $this->kizeoLogger->info('Data IDs récupérés pour formulaire', [
                'form_id' => $formId,
                'count' => count($dataIds),
            ]);

            $this->kizeoLogger->debug('Raw data IDs sample', [
                'first_3' => array_slice($dataIds, 0, 3),
                'type_first' => gettype($dataIds[0] ?? null),
            ]);
            
            // L'API peut retourner des objets {"id": "xxx"} ou des scalaires
            return array_map(function ($item) {
                if (is_array($item)) {
                    return (int) ($item['id'] ?? 0);
                }
                return (int) $item;
            }, $dataIds);
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération data IDs', [
                'form_id' => $formId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Marque une liste de data_ids comme NON LUS
     * 
     * Endpoint miroir de markasreadbyaction : markasunreadbyaction
     * POST /forms/{formId}/markasunreadbyaction/read
     * Body: { "data_ids": [id1, id2, ...] }
     */
    public function markAsUnread(int $formId, array $dataIds): bool
    {
        if (empty($dataIds)) {
            return true;
        }
        
        $endpoint = sprintf('/forms/%d/markasunreadbyaction/read', $formId);
        
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . $endpoint, [
                'headers' => [
                    'Authorization' => $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'data_ids' => array_map('intval', $dataIds),
                ],
                'timeout' => self::TIMEOUT,
            ]);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode === 200) {
                $this->kizeoLogger->info('Formulaires marqués comme NON LUS', [
                    'form_id' => $formId,
                    'count' => count($dataIds),
                ]);
                return true;
            }
            
            $this->kizeoLogger->warning('Marquage non-lu - code inattendu', [
                'form_id' => $formId,
                'status_code' => $statusCode,
            ]);
            return false;
            
        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur marquage non-lu', [
                'form_id' => $formId,
                'count' => count($dataIds),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // MÉTHODE PRIVÉE - REQUÊTE GÉNÉRIQUE
    // =========================================================================

    /**
     * Effectue une requête à l'API Kizeo
     * 
     * @param array<string, mixed> $options
     * @return array<mixed>
     * @throws \Exception
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
        
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Erreur API Kizeo: %s %s - HTTP %d',
                $method,
                $endpoint,
                $statusCode
            ));
        }
        
        $content = $response->getContent();
        
        if (empty($content)) {
            return [];
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Erreur décodage JSON: %s',
                json_last_error_msg()
            ));
        }
        
        return $data;
    }
}