<?php

declare(strict_types=1);

namespace App\Service\Kizeo;

use App\Repository\AgencyRepository;
use Psr\Log\LoggerInterface;

/**
 * Service pour récupérer et parser les listes clients depuis Kizeo Forms
 * 
 * Structure liste clients Kizeo :
 * "NOM:NOM|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE"
 * 
 * Structure liste équipements Kizeo :
 * "CLIENT\\VISITE\\NUM_EQUIP|LIBELLE|...|ID_CONTACT|ID_SOCIETE|CODE_AGENCE"
 * 
 * @author Alex - SOMAFI GROUP
 */
class KizeoClientService
{
    public function __construct(
        private readonly KizeoApiService $kizeoApi,
        private readonly AgencyRepository $agencyRepository,
        private readonly LoggerInterface $kizeoLogger,
    ) {
    }

    /**
     * Récupère la liste des clients d'une agence depuis Kizeo
     * 
     * @return array<int, array{
     *     raison_sociale: string,
     *     code_postal: string,
     *     ville: string,
     *     id_contact: int,
     *     code_agence: string,
     *     id_societe: int|null
     * }>
     */
    public function getClientsByAgency(string $agencyCode): array
    {
        $agency = $this->agencyRepository->findOneBy(['code' => $agencyCode]);
        
        if (!$agency || !$agency->getKizeoListId()) {
            $this->kizeoLogger->warning('Agence non trouvée ou sans kizeo_list_id', [
                'agency_code' => $agencyCode,
            ]);
            return [];
        }

        $listId = $agency->getKizeoListId();
        
        try {
            $response = $this->kizeoApi->getList($listId);
            
            if (!$response || !isset($response['list']['items'])) {
                $this->kizeoLogger->warning('Réponse Kizeo vide ou invalide', [
                    'agency_code' => $agencyCode,
                    'list_id' => $listId,
                ]);
                return [];
            }

            $clients = $this->parseClientItems($response['list']['items']);
            
            $this->kizeoLogger->info('Clients récupérés depuis Kizeo', [
                'agency_code' => $agencyCode,
                'count' => count($clients),
            ]);

            return $clients;

        } catch (\Exception $e) {
            $this->kizeoLogger->error('Erreur récupération liste clients Kizeo', [
                'agency_code' => $agencyCode,
                'list_id' => $listId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse les items de la liste Kizeo en tableau structuré
     * 
     * Format Kizeo : "NOM:NOM|CP:CP|VILLE:VILLE|ID_CONTACT:ID_CONTACT|CODE_AGENCE:CODE_AGENCE|ID_SOCIETE:ID_SOCIETE"
     * 
     * @param array<string> $items
     * @return array<int, array{
     *     raison_sociale: string,
     *     code_postal: string,
     *     ville: string,
     *     id_contact: int,
     *     code_agence: string,
     *     id_societe: int|null
     * }>
     */
    private function parseClientItems(array $items): array
    {
        $clients = [];

        foreach ($items as $item) {
            $parsed = $this->parseClientLine($item);
            if ($parsed !== null) {
                $clients[] = $parsed;
            }
        }

        // Tri alphabétique par raison sociale
        usort($clients, fn($a, $b) => strcasecmp($a['raison_sociale'], $b['raison_sociale']));

        return $clients;
    }

    /**
     * Parse une ligne client Kizeo
     * 
     * @return array{
     *     raison_sociale: string,
     *     code_postal: string,
     *     ville: string,
     *     id_contact: int,
     *     code_agence: string,
     *     id_societe: int|null
     * }|null
     */
    private function parseClientLine(string $line): ?array
    {
        // Séparer les colonnes par |
        $columns = explode('|', $line);
        
        if (count($columns) < 5) {
            $this->kizeoLogger->debug('Ligne client invalide (colonnes insuffisantes)', [
                'line' => $line,
            ]);
            return null;
        }

        // Chaque colonne est au format "valeur:valeur"
        // On prend la première partie (avant le :)
        $raisonSociale = $this->extractValue($columns[0]);
        $codePostal = $this->extractValue($columns[1]);
        $ville = $this->extractValue($columns[2]);
        $idContact = $this->extractValue($columns[3]);
        $codeAgence = $this->extractValue($columns[4]);
        
        // ID Société est optionnel (index 5) - anciennement appelé "id_contrat_cadre" par erreur
        $idSociete = isset($columns[5]) ? $this->extractValue($columns[5]) : null;

        if (!$raisonSociale || !$idContact) {
            return null;
        }

        return [
            'raison_sociale' => $raisonSociale,
            'code_postal' => $codePostal ?: '',
            'ville' => $ville ?: '',
            'id_contact' => (int) $idContact,
            'code_agence' => $codeAgence ?: '',
            'id_societe' => $idSociete ? (int) $idSociete : null,
        ];
    }

    /**
     * Extrait la valeur d'une colonne Kizeo (format "valeur:valeur")
     */
    private function extractValue(string $column): string
    {
        $parts = explode(':', $column, 2);
        return trim($parts[0]);
    }

    /**
     * Recherche des clients par nom (filtrage côté PHP)
     * 
     * @return array<int, array{
     *     raison_sociale: string,
     *     code_postal: string,
     *     ville: string,
     *     id_contact: int,
     *     code_agence: string,
     *     id_societe: int|null
     * }>
     */
    public function searchClients(string $agencyCode, string $search): array
    {
        $clients = $this->getClientsByAgency($agencyCode);
        
        if (empty($search)) {
            return $clients;
        }

        $searchLower = mb_strtolower($search);
        
        return array_values(array_filter($clients, function ($client) use ($searchLower) {
            return str_contains(mb_strtolower($client['raison_sociale']), $searchLower)
                || str_contains(mb_strtolower($client['ville']), $searchLower)
                || str_contains($client['code_postal'], $searchLower);
        }));
    }

    /**
     * Récupère un client par son id_contact
     * 
     * @return array{
     *     raison_sociale: string,
     *     code_postal: string,
     *     ville: string,
     *     id_contact: int,
     *     code_agence: string,
     *     id_societe: int|null
     * }|null
     */
    public function getClientByIdContact(string $agencyCode, int $idContact): ?array
    {
        $clients = $this->getClientsByAgency($agencyCode);
        
        foreach ($clients as $client) {
            if ($client['id_contact'] === $idContact) {
                return $client;
            }
        }
        
        return null;
    }

    /**
     * Enrichit les clients avec les coordonnées depuis les tables contact_sXX
     * 
     * @param array<int, array> $clients
     * @param array<int, array> $contactsGestan Contacts de la table contact_sXX indexés par id_contact
     * @return array<int, array>
     */
    public function enrichWithGestanData(array $clients, array $contactsGestan): array
    {
        foreach ($clients as &$client) {
            $idContact = $client['id_contact'];
            
            if (isset($contactsGestan[$idContact])) {
                $gestan = $contactsGestan[$idContact];
                $client['adresse'] = $gestan['adressep_1'] ?? '';
                $client['adresse2'] = $gestan['adressep_2'] ?? '';
                $client['telephone'] = $gestan['telephone'] ?? '';
                $client['email'] = $gestan['email'] ?? '';
            }
        }

        return $clients;
    }
}