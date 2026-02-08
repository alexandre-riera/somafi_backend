<?php

namespace App\Entity\Agency;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait contenant tous les champs communs aux 13 entités Equipement
 * 
 * Utilisé par EquipementS10, EquipementS40, etc.
 */
trait EquipementTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * ID du contact/client - CLÉ DE LIAISON
     */
    #[ORM\Column]
    private ?int $idContact = null;

    /**
     * Numéro de l'équipement (ex: RAP01, SEC02, NIV03)
     */
    #[ORM\Column(length: 50)]
    private ?string $numeroEquipement = null;

    /**
     * Numéro de l'équipement chez le client si différent (ex: RAP01 -> RAP01-ATELIER-3, SEC001 -> SEC1001)
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $numeroEquipementClient = null;

    /**
     * Libellé/Type d'équipement (ex: "Porte rapide", "Porte sectionnelle")
     */
    #[ORM\Column(length: 255)]
    private ?string $libelleEquipement = null;

    /**
     * Type de visite : CEA, CE1, CE2, CE3, CE4
     */
    #[ORM\Column(length: 5)]
    private ?string $visite = null;

    /**
     * Année de la visite
     */
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $annee = null;

    /**
     * Date de la dernière visite
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDerniereVisite = null;

    /**
     * Repère interne du client sur le site
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $repereSiteClient = null;

    /**
     * Année de mise en service
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $miseEnService = null;

    /**
     * Numéro de série
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $numeroSerie = null;

    /**
     * Marque du fabricant
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $marque = null;

    /**
     * Mode de fonctionnement (Motorisé / Manuel)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $modeFonctionnement = null;

    /**
     * Dimensions
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $hauteur = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $largeur = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $longueur = null;

    /**
     * État de l'équipement (texte descriptif)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $etatEquipement = null;

    /**
     * Statut/Code état (A, B, C, D, E, F, G)
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $statutEquipement = null;

    /**
     * Anomalies constatées
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anomalies = null;

    /**
     * Observations
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    /**
     * Trigramme du technicien
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $trigrammeTech = null;

    /**
     * Signature du technicien (chemin ou base64)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signatureTech = null;

    /**
     * Indique si l'équipement est au contrat ou hors contrat
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isHorsContrat = false;

    /**
     * Pour archivage logique
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isArchive = false;

    // ===== CHAMPS DÉDUPLICATION KIZEO =====

    /**
     * ID du formulaire Kizeo source
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoFormId = null;

    /**
     * ID de la donnée Kizeo source
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoDataId = null;

    /**
     * Index dans le tableau (pour équipements hors contrat)
     * Clé de déduplication: form_id + data_id + kizeo_index
     */
    #[ORM\Column(nullable: true)]
    private ?int $kizeoIndex = null;

    // ===== TIMESTAMPS =====

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateEnregistrement = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateModification = null;

    // ===== GETTERS & SETTERS =====

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdContact(): ?int
    {
        return $this->idContact;
    }

    public function setIdContact(int $idContact): static
    {
        $this->idContact = $idContact;
        return $this;
    }

    public function getNumeroEquipement(): ?string
    {
        return $this->numeroEquipement;
    }

    public function setNumeroEquipement(string $numeroEquipement): static
    {
        $this->numeroEquipement = strtoupper($numeroEquipement);
        return $this;
    }
    
    public function getNumeroEquipementClient(): ?string
    {
        return $this->numeroEquipementClient;
    }

    public function setNumeroEquipementClient(string $numeroEquipementClient): static
    {
        $this->numeroEquipementClient = strtoupper($numeroEquipementClient);
        return $this;
    }

    public function getLibelleEquipement(): ?string
    {
        return $this->libelleEquipement;
    }

    public function setLibelleEquipement(string $libelleEquipement): static
    {
        $this->libelleEquipement = $libelleEquipement;
        return $this;
    }

    public function getVisite(): ?string
    {
        return $this->visite;
    }

    public function setVisite(string $visite): static
    {
        $this->visite = strtoupper($visite);
        return $this;
    }

    public function getAnnee(): ?string
    {
        return $this->annee;
    }

    public function setAnnee(?string $annee): static
    {
        $this->annee = $annee;
        return $this;
    }

    public function getDateDerniereVisite(): ?\DateTimeInterface
    {
        return $this->dateDerniereVisite;
    }

    public function setDateDerniereVisite(?\DateTimeInterface $dateDerniereVisite): static
    {
        $this->dateDerniereVisite = $dateDerniereVisite;
        return $this;
    }

    public function getRepereSiteClient(): ?string
    {
        return $this->repereSiteClient;
    }

    public function setRepereSiteClient(?string $repereSiteClient): static
    {
        $this->repereSiteClient = $repereSiteClient;
        return $this;
    }

    public function getMiseEnService(): ?string
    {
        return $this->miseEnService;
    }

    public function setMiseEnService(?string $miseEnService): static
    {
        $this->miseEnService = $miseEnService;
        return $this;
    }

    public function getNumeroSerie(): ?string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(?string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie;
        return $this;
    }

    public function getMarque(): ?string
    {
        return $this->marque;
    }

    public function setMarque(?string $marque): static
    {
        $this->marque = $marque;
        return $this;
    }

    public function getModeFonctionnement(): ?string
    {
        return $this->modeFonctionnement;
    }

    public function setModeFonctionnement(?string $modeFonctionnement): static
    {
        $this->modeFonctionnement = $modeFonctionnement;
        return $this;
    }

    public function getHauteur(): ?string
    {
        return $this->hauteur;
    }

    public function setHauteur(?string $hauteur): static
    {
        $this->hauteur = $hauteur;
        return $this;
    }

    public function getLargeur(): ?string
    {
        return $this->largeur;
    }

    public function setLargeur(?string $largeur): static
    {
        $this->largeur = $largeur;
        return $this;
    }

    public function getLongueur(): ?string
    {
        return $this->longueur;
    }

    public function setLongueur(?string $longueur): static
    {
        $this->longueur = $longueur;
        return $this;
    }

    public function getEtatEquipement(): ?string
    {
        return $this->etatEquipement;
    }

    public function setEtatEquipement(?string $etatEquipement): static
    {
        $this->etatEquipement = $etatEquipement;
        return $this;
    }

    public function getStatutEquipement(): ?string
    {
        return $this->statutEquipement;
    }

    public function setStatutEquipement(?string $statutEquipement): static
    {
        $this->statutEquipement = strtoupper($statutEquipement ?? '');
        return $this;
    }

    public function getAnomalies(): ?string
    {
        return $this->anomalies;
    }

    public function setAnomalies(?string $anomalies): static
    {
        $this->anomalies = $anomalies;
        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): static
    {
        $this->observations = $observations;
        return $this;
    }

    public function getTrigrammeTech(): ?string
    {
        return $this->trigrammeTech;
    }

    public function setTrigrammeTech(?string $trigrammeTech): static
    {
        $this->trigrammeTech = $trigrammeTech;
        return $this;
    }

    public function getSignatureTech(): ?string
    {
        return $this->signatureTech;
    }

    public function setSignatureTech(?string $signatureTech): static
    {
        $this->signatureTech = $signatureTech;
        return $this;
    }

    public function isHorsContrat(): bool
    {
        return $this->isHorsContrat;
    }

    public function setIsHorsContrat(bool $isHorsContrat): static
    {
        $this->isHorsContrat = $isHorsContrat;
        return $this;
    }

    public function isArchive(): bool
    {
        return $this->isArchive;
    }

    public function setIsArchive(bool $isArchive): static
    {
        $this->isArchive = $isArchive;
        return $this;
    }

    public function getKizeoFormId(): ?int
    {
        return $this->kizeoFormId;
    }

    public function setKizeoFormId(?int $kizeoFormId): static
    {
        $this->kizeoFormId = $kizeoFormId;
        return $this;
    }

    public function getKizeoDataId(): ?int
    {
        return $this->kizeoDataId;
    }

    public function setKizeoDataId(?int $kizeoDataId): static
    {
        $this->kizeoDataId = $kizeoDataId;
        return $this;
    }

    public function getKizeoIndex(): ?int
    {
        return $this->kizeoIndex;
    }

    public function setKizeoIndex(?int $kizeoIndex): static
    {
        $this->kizeoIndex = $kizeoIndex;
        return $this;
    }

    public function getDateEnregistrement(): ?\DateTimeInterface
    {
        return $this->dateEnregistrement;
    }

    public function setDateEnregistrement(\DateTimeInterface $dateEnregistrement): static
    {
        $this->dateEnregistrement = $dateEnregistrement;
        return $this;
    }

    public function getDateModification(): ?\DateTimeInterface
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeInterface $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }

    // ===== MÉTHODES UTILITAIRES =====

    /**
     * Retourne la couleur de la pastille selon le statut
     */
    public function getStatusColor(): string
    {
        return match ($this->statutEquipement) {
            'A' => '#28a745',      // Vert - Bon état
            'B' => '#fd7e14',      // Orange - Travaux préventifs
            'C' => '#dc3545',      // Rouge - Travaux curatifs
            'D', 'E', 'F', 'G' => '#343a40',  // Noir - Autres cas
            default => '#6c757d', // Gris - Non défini
        };
    }

    /**
     * Retourne le libellé du statut
     */
    public function getStatusLabel(): string
    {
        if ($this->isHorsContrat) {
            return match ($this->statutEquipement) {
                'A' => 'Bon état',
                'B' => 'Travaux à prévoir',
                'C' => 'Travaux urgents',
                'D' => 'Inaccessible',
                'E' => 'À l\'arrêt',
                'F' => 'Mis à l\'arrêt',
                'G' => 'Non présent',
                default => 'Non défini',
            };
        }

        return match ($this->statutEquipement) {
            'A' => 'Bon état de fonctionnement',
            'B' => 'Travaux préventifs',
            'C' => 'Travaux curatifs',
            'D' => 'Équipement inaccessible',
            'E' => 'Équipement à l\'arrêt',
            'F' => 'Mis à l\'arrêt lors de l\'intervention',
            'G' => 'Non présent sur site',
            default => 'Non défini',
        };
    }

    /**
     * Clé de déduplication pour les équipements hors contrat
     */
    public function getDeduplicationKey(): string
    {
        return sprintf('%d-%d-%d', $this->kizeoFormId ?? 0, $this->kizeoDataId ?? 0, $this->kizeoIndex ?? 0);
    }

    /**
     * Clé de déduplication pour les équipements au contrat
     */
    public function getContractDeduplicationKey(): string
    {
        return sprintf(
            '%d-%s-%s-%s',
            $this->idContact ?? 0,
            $this->numeroEquipement ?? '',
            $this->visite ?? '',
            $this->dateDerniereVisite?->format('Y-m-d') ?? ''
        );
    }
}
