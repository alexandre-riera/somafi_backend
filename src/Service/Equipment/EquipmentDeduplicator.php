<?php

namespace App\Service\Equipment;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de déduplication des équipements
 * 
 * CRITIQUE: La déduplication est essentielle pour éviter les doublons
 * 
 * Règles:
 * - Équipements AU CONTRAT: clé = id_contact + numero + visite + date
 * - Équipements HORS CONTRAT: clé = form_id + data_id + index
 */
class EquipmentDeduplicator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EquipmentFactory $equipmentFactory,
        private readonly LoggerInterface $equipmentLogger,
    ) {
    }

    /**
     * Vérifie si un équipement AU CONTRAT existe déjà
     */
    public function existsContractEquipment(
        string $agencyCode,
        int $idContact,
        string $numero,
        string $visite,
        ?\DateTimeInterface $dateVisite
    ): bool {
        $entityClass = $this->equipmentFactory->getEntityClassForAgency($agencyCode);
        $repo = $this->em->getRepository($entityClass);
        
        if (!$dateVisite) {
            return false;
        }

        return $repo->existsByContractKey($idContact, $numero, $visite, $dateVisite);
    }

    /**
     * Vérifie si un équipement HORS CONTRAT existe déjà
     * Clé de déduplication: form_id + data_id + index
     */
    public function existsOffContractEquipment(
        string $agencyCode,
        int $formId,
        int $dataId,
        int $index
    ): bool {
        $entityClass = $this->equipmentFactory->getEntityClassForAgency($agencyCode);
        $repo = $this->em->getRepository($entityClass);

        return $repo->existsByKizeoKey($formId, $dataId, $index);
    }

    /**
     * Trouve et supprime les doublons pour un contact
     * À utiliser avec précaution
     * 
     * @return int Nombre de doublons supprimés
     */
    public function removeDuplicates(string $agencyCode, int $idContact): int
    {
        $entityClass = $this->equipmentFactory->getEntityClassForAgency($agencyCode);
        $repo = $this->em->getRepository($entityClass);

        // Récupérer tous les équipements du contact
        $equipments = $repo->findBy(['idContact' => $idContact]);

        $seen = [];
        $toRemove = [];

        foreach ($equipments as $equip) {
            // Construire la clé de déduplication
            if ($equip->isHorsContrat()) {
                $key = $equip->getDeduplicationKey();
            } else {
                $key = $equip->getContractDeduplicationKey();
            }

            if (isset($seen[$key])) {
                // Doublon trouvé - garder le plus récent
                $existing = $seen[$key];
                if ($equip->getDateEnregistrement() > $existing->getDateEnregistrement()) {
                    $toRemove[] = $existing;
                    $seen[$key] = $equip;
                } else {
                    $toRemove[] = $equip;
                }
            } else {
                $seen[$key] = $equip;
            }
        }

        // Supprimer les doublons
        foreach ($toRemove as $equip) {
            $this->em->remove($equip);
            $this->equipmentLogger->info('Doublon supprimé', [
                'id' => $equip->getId(),
                'numero' => $equip->getNumeroEquipement(),
                'id_contact' => $idContact,
            ]);
        }

        if (count($toRemove) > 0) {
            $this->em->flush();
        }

        return count($toRemove);
    }
}
