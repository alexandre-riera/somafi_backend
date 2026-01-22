<?php

namespace App\Service\Equipment;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Génère les numéros pour les équipements HORS CONTRAT
 * 
 * Règle: Dernier numéro de même type + 1
 * Ex: Si le dernier équipement de type "Porte sectionnelle" est SEC03,
 *     le nouveau sera SEC04
 */
class OffContractNumberGenerator
{
    /**
     * Mapping type d'équipement -> préfixe
     */
    private const TYPE_PREFIXES = [
        // Portes
        'porte rapide' => 'RAP',
        'porte sectionnelle' => 'SEC',
        'porte coupe-feu' => 'CFE',
        'porte souple' => 'SOU',
        'porte basculante' => 'BAS',
        'porte coulissante' => 'COU',
        'porte pivotante' => 'PIV',
        'porte piétonne' => 'PIE',
        
        // Portails et barrières
        'portail' => 'POR',
        'portail coulissant' => 'POC',
        'portail battant' => 'POB',
        'barrière' => 'BAR',
        'barrière levante' => 'BAL',
        
        // Niveleurs et quais
        'niveleur' => 'NIV',
        'niveleur de quai' => 'NIV',
        'quai' => 'QUA',
        'rampe' => 'RAM',
        
        // Autres
        'rideau métallique' => 'RID',
        'rideau' => 'RID',
        'grille' => 'GRI',
        'store' => 'STO',
        'volet' => 'VOL',
        'sas' => 'SAS',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly LoggerInterface $equipmentLogger,
    ) {
    }

    /**
     * Génère un nouveau numéro d'équipement
     * 
     * @param string $agencyCode Code agence (S10, S40, etc.)
     * @param int $idContact ID du contact
     * @param string $libelle Libellé/type de l'équipement
     * @return string Numéro généré (ex: SEC04)
     */
    public function generate(string $agencyCode, int $idContact, string $libelle): string
    {
        $prefix = $this->getPrefixForType($libelle);
        
        // Trouver le dernier numéro existant avec ce préfixe
        $entityClass = $this->equipmentFactory->getEntityClassForAgency($agencyCode);
        $repo = $this->em->getRepository($entityClass);
        
        $lastNumber = $repo->findLastNumberForType($idContact, $prefix);
        
        if ($lastNumber) {
            // Extraire le numéro et incrémenter
            $num = (int) substr($lastNumber, strlen($prefix));
            $newNum = $num + 1;
        } else {
            $newNum = 1;
        }

        $newNumber = sprintf('%s%02d', $prefix, $newNum);

        $this->equipmentLogger->debug('Numéro HC généré', [
            'libelle' => $libelle,
            'prefix' => $prefix,
            'numero' => $newNumber,
        ]);

        return $newNumber;
    }

    /**
     * Détermine le préfixe à utiliser pour un type d'équipement
     */
    private function getPrefixForType(string $libelle): string
    {
        $libelleLower = strtolower(trim($libelle));

        // Chercher une correspondance exacte ou partielle
        foreach (self::TYPE_PREFIXES as $type => $prefix) {
            if (str_contains($libelleLower, $type)) {
                return $prefix;
            }
        }

        // Si aucune correspondance, créer un préfixe à partir des 3 premières lettres
        $sanitized = preg_replace('/[^a-z]/', '', $libelleLower);
        if (strlen($sanitized) >= 3) {
            return strtoupper(substr($sanitized, 0, 3));
        }

        // Fallback
        return 'EQP';
    }

    /**
     * Retourne le mapping complet des préfixes
     * 
     * @return array<string, string>
     */
    public function getAllPrefixes(): array
    {
        return self::TYPE_PREFIXES;
    }
}
