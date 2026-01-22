<?php

namespace App\Service\Equipment;

use App\Entity\Agency\EquipementS10;
use App\Entity\Agency\EquipementS40;
use App\Entity\Agency\EquipementS50;
use App\Entity\Agency\EquipementS60;
use App\Entity\Agency\EquipementS70;
use App\Entity\Agency\EquipementS80;
use App\Entity\Agency\EquipementS100;
use App\Entity\Agency\EquipementS120;
use App\Entity\Agency\EquipementS130;
use App\Entity\Agency\EquipementS140;
use App\Entity\Agency\EquipementS150;
use App\Entity\Agency\EquipementS160;
use App\Entity\Agency\EquipementS170;

/**
 * Factory pour créer les entités Equipement selon l'agence
 */
class EquipmentFactory
{
    /**
     * Mapping code agence -> classe entité
     */
    private const ENTITY_MAP = [
        'S10' => EquipementS10::class,
        'S40' => EquipementS40::class,
        'S50' => EquipementS50::class,
        'S60' => EquipementS60::class,
        'S70' => EquipementS70::class,
        'S80' => EquipementS80::class,
        'S100' => EquipementS100::class,
        'S120' => EquipementS120::class,
        'S130' => EquipementS130::class,
        'S140' => EquipementS140::class,
        'S150' => EquipementS150::class,
        'S160' => EquipementS160::class,
        'S170' => EquipementS170::class,
    ];

    public function __construct(
        private readonly array $agencies,
    ) {
    }

    /**
     * Crée une nouvelle entité Equipement pour une agence donnée
     */
    public function createForAgency(string $agencyCode): object
    {
        $code = strtoupper($agencyCode);
        
        if (!isset(self::ENTITY_MAP[$code])) {
            throw new \InvalidArgumentException(sprintf(
                'Agence inconnue: %s. Agences valides: %s',
                $agencyCode,
                implode(', ', array_keys(self::ENTITY_MAP))
            ));
        }

        $className = self::ENTITY_MAP[$code];
        return new $className();
    }

    /**
     * Retourne la classe entité pour une agence
     */
    public function getEntityClassForAgency(string $agencyCode): string
    {
        $code = strtoupper($agencyCode);
        
        if (!isset(self::ENTITY_MAP[$code])) {
            throw new \InvalidArgumentException(sprintf('Agence inconnue: %s', $agencyCode));
        }

        return self::ENTITY_MAP[$code];
    }

    /**
     * Vérifie si une agence existe
     */
    public function agencyExists(string $agencyCode): bool
    {
        return isset(self::ENTITY_MAP[strtoupper($agencyCode)]);
    }

    /**
     * Retourne la liste de toutes les agences
     * 
     * @return array<string>
     */
    public function getAllAgencyCodes(): array
    {
        return array_keys(self::ENTITY_MAP);
    }

    /**
     * Retourne le nom de la table pour une agence
     */
    public function getTableNameForAgency(string $agencyCode): string
    {
        return 'equipement_' . strtolower($agencyCode);
    }
}
