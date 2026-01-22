<?php

namespace App\Entity;

use App\Repository\PhotoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stocke les références des photos d'équipements récupérées depuis Kizeo Forms.
 * Les fichiers .jpeg sont téléchargés via l'API Kizeo et stockés localement.
 * 
 * Convention de nommage des fichiers stockés :
 * - Équipement AU CONTRAT : {numero_equipement}_generale.jpg
 * - Équipement HORS CONTRAT : {numero_equipement}_compte_rendu.jpg
 * 
 * Chemin de stockage : public/img/{code_agence}/{id_contact}/{annee}/{visite}/
 */
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photos')]
#[ORM\Index(columns: ['id_contact'], name: 'idx_photo_contact')]
#[ORM\Index(columns: ['code_agence'], name: 'idx_photo_agence')]
#[ORM\Index(columns: ['equipment_id'], name: 'idx_photo_equipment')]
#[ORM\Index(columns: ['form_id', 'data_id'], name: 'idx_photo_kizeo')]
class Photo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ========== Champs de liaison Kizeo ==========
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $form_id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $data_id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $equipment_id = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $code_equipement = null;

    // ========== Champs de liaison métier ==========

    #[ORM\Column(length: 50)]
    private ?string $id_contact = null;

    #[ORM\Column(length: 50)]
    private ?string $id_societe = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $code_agence = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $visite = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $annee = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $raison_sociale_visite = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $update_time = null;

    // ========== Photos générales ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_plaque = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_etiquette_somafi = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_environnement_equipement1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_envirronement_eclairage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_compte_rendu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_2 = null;

    // ========== Photos chocs/dommages ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_choc = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_choc_montant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_choc_tablier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_choc_tablier_porte = null;

    // ========== Photos déformations ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_deformation_plateau = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_deformation_plaque = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_deformation_structure = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_deformation_chassis = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_deformation_levre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_fissure_cordon = null;

    // ========== Photos panneaux/lames ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_panneau_intermediaire_i = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_panneau_bas_inter_ext = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_lame_basse__int_ext = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_lame_intermediaire_int_ = null;

    // ========== Photos composants techniques ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_coffret_de_commande = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_carte = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_rail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_equerre_rail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_fixation_coulisse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_moteur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_axe = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_serrure = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_serrure1 = null;

    // ========== Photos éléments spécifiques ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_bache = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_joue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_butoir = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_vantail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_linteau = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_barriere = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_tourniquet = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_sas = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_feux = null;

    // ========== Photos marquage au sol ==========

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_marquage_au_sol = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_marquage_au_sol_ = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo_marquage_au_sol_2 = null;

    // ========== GETTERS / SETTERS ==========

    public function getId(): ?int
    {
        return $this->id;
    }

    // --- Liaison Kizeo ---

    public function getFormId(): ?string
    {
        return $this->form_id;
    }

    public function setFormId(?string $form_id): static
    {
        $this->form_id = $form_id;
        return $this;
    }

    public function getDataId(): ?string
    {
        return $this->data_id;
    }

    public function setDataId(?string $data_id): static
    {
        $this->data_id = $data_id;
        return $this;
    }

    public function getEquipmentId(): ?string
    {
        return $this->equipment_id;
    }

    public function setEquipmentId(?string $equipment_id): static
    {
        $this->equipment_id = $equipment_id;
        return $this;
    }

    public function getCodeEquipement(): ?string
    {
        return $this->code_equipement;
    }

    public function setCodeEquipement(?string $code_equipement): static
    {
        $this->code_equipement = $code_equipement;
        return $this;
    }

    // --- Liaison métier ---

    public function getIdContact(): ?string
    {
        return $this->id_contact;
    }

    public function setIdContact(string $id_contact): static
    {
        $this->id_contact = $id_contact;
        return $this;
    }

    public function getIdSociete(): ?string
    {
        return $this->id_societe;
    }

    public function setIdSociete(string $id_societe): static
    {
        $this->id_societe = $id_societe;
        return $this;
    }

    public function getCodeAgence(): ?string
    {
        return $this->code_agence;
    }

    public function setCodeAgence(?string $code_agence): static
    {
        $this->code_agence = $code_agence;
        return $this;
    }

    public function getVisite(): ?string
    {
        return $this->visite;
    }

    public function setVisite(?string $visite): static
    {
        $this->visite = $visite;
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

    public function getRaisonSocialeVisite(): ?string
    {
        return $this->raison_sociale_visite;
    }

    public function setRaisonSocialeVisite(?string $raison_sociale_visite): static
    {
        $this->raison_sociale_visite = $raison_sociale_visite;
        return $this;
    }

    public function getUpdateTime(): ?string
    {
        return $this->update_time;
    }

    public function setUpdateTime(?string $update_time): static
    {
        $this->update_time = $update_time;
        return $this;
    }

    // --- Photos générales ---

    public function getPhotoPlaque(): ?string
    {
        return $this->photo_plaque;
    }

    public function setPhotoPlaque(?string $photo_plaque): static
    {
        $this->photo_plaque = $photo_plaque;
        return $this;
    }

    public function getPhotoEtiquetteSomafi(): ?string
    {
        return $this->photo_etiquette_somafi;
    }

    public function setPhotoEtiquetteSomafi(?string $photo_etiquette_somafi): static
    {
        $this->photo_etiquette_somafi = $photo_etiquette_somafi;
        return $this;
    }

    public function getPhotoEnvironnementEquipement1(): ?string
    {
        return $this->photo_environnement_equipement1;
    }

    public function setPhotoEnvironnementEquipement1(?string $photo_environnement_equipement1): static
    {
        $this->photo_environnement_equipement1 = $photo_environnement_equipement1;
        return $this;
    }

    public function getPhotoEnvirronementEclairage(): ?string
    {
        return $this->photo_envirronement_eclairage;
    }

    public function setPhotoEnvirronementEclairage(?string $photo_envirronement_eclairage): static
    {
        $this->photo_envirronement_eclairage = $photo_envirronement_eclairage;
        return $this;
    }

    public function getPhotoCompteRendu(): ?string
    {
        return $this->photo_compte_rendu;
    }

    public function setPhotoCompteRendu(?string $photo_compte_rendu): static
    {
        $this->photo_compte_rendu = $photo_compte_rendu;
        return $this;
    }

    public function getPhoto2(): ?string
    {
        return $this->photo_2;
    }

    public function setPhoto2(?string $photo_2): static
    {
        $this->photo_2 = $photo_2;
        return $this;
    }

    // --- Photos chocs/dommages ---

    public function getPhotoChoc(): ?string
    {
        return $this->photo_choc;
    }

    public function setPhotoChoc(?string $photo_choc): static
    {
        $this->photo_choc = $photo_choc;
        return $this;
    }

    public function getPhotoChocMontant(): ?string
    {
        return $this->photo_choc_montant;
    }

    public function setPhotoChocMontant(?string $photo_choc_montant): static
    {
        $this->photo_choc_montant = $photo_choc_montant;
        return $this;
    }

    public function getPhotoChocTablier(): ?string
    {
        return $this->photo_choc_tablier;
    }

    public function setPhotoChocTablier(?string $photo_choc_tablier): static
    {
        $this->photo_choc_tablier = $photo_choc_tablier;
        return $this;
    }

    public function getPhotoChocTablierPorte(): ?string
    {
        return $this->photo_choc_tablier_porte;
    }

    public function setPhotoChocTablierPorte(?string $photo_choc_tablier_porte): static
    {
        $this->photo_choc_tablier_porte = $photo_choc_tablier_porte;
        return $this;
    }

    // --- Photos déformations ---

    public function getPhotoDeformationPlateau(): ?string
    {
        return $this->photo_deformation_plateau;
    }

    public function setPhotoDeformationPlateau(?string $photo_deformation_plateau): static
    {
        $this->photo_deformation_plateau = $photo_deformation_plateau;
        return $this;
    }

    public function getPhotoDeformationPlaque(): ?string
    {
        return $this->photo_deformation_plaque;
    }

    public function setPhotoDeformationPlaque(?string $photo_deformation_plaque): static
    {
        $this->photo_deformation_plaque = $photo_deformation_plaque;
        return $this;
    }

    public function getPhotoDeformationStructure(): ?string
    {
        return $this->photo_deformation_structure;
    }

    public function setPhotoDeformationStructure(?string $photo_deformation_structure): static
    {
        $this->photo_deformation_structure = $photo_deformation_structure;
        return $this;
    }

    public function getPhotoDeformationChassis(): ?string
    {
        return $this->photo_deformation_chassis;
    }

    public function setPhotoDeformationChassis(?string $photo_deformation_chassis): static
    {
        $this->photo_deformation_chassis = $photo_deformation_chassis;
        return $this;
    }

    public function getPhotoDeformationLevre(): ?string
    {
        return $this->photo_deformation_levre;
    }

    public function setPhotoDeformationLevre(?string $photo_deformation_levre): static
    {
        $this->photo_deformation_levre = $photo_deformation_levre;
        return $this;
    }

    public function getPhotoFissureCordon(): ?string
    {
        return $this->photo_fissure_cordon;
    }

    public function setPhotoFissureCordon(?string $photo_fissure_cordon): static
    {
        $this->photo_fissure_cordon = $photo_fissure_cordon;
        return $this;
    }

    // --- Photos panneaux/lames ---

    public function getPhotoPanneauIntermediaireI(): ?string
    {
        return $this->photo_panneau_intermediaire_i;
    }

    public function setPhotoPanneauIntermediaireI(?string $photo_panneau_intermediaire_i): static
    {
        $this->photo_panneau_intermediaire_i = $photo_panneau_intermediaire_i;
        return $this;
    }

    public function getPhotoPanneauBasInterExt(): ?string
    {
        return $this->photo_panneau_bas_inter_ext;
    }

    public function setPhotoPanneauBasInterExt(?string $photo_panneau_bas_inter_ext): static
    {
        $this->photo_panneau_bas_inter_ext = $photo_panneau_bas_inter_ext;
        return $this;
    }

    public function getPhotoLameBasseIntExt(): ?string
    {
        return $this->photo_lame_basse__int_ext;
    }

    public function setPhotoLameBasseIntExt(?string $photo_lame_basse__int_ext): static
    {
        $this->photo_lame_basse__int_ext = $photo_lame_basse__int_ext;
        return $this;
    }

    public function getPhotoLameIntermediaireInt(): ?string
    {
        return $this->photo_lame_intermediaire_int_;
    }

    public function setPhotoLameIntermediaireInt(?string $photo_lame_intermediaire_int_): static
    {
        $this->photo_lame_intermediaire_int_ = $photo_lame_intermediaire_int_;
        return $this;
    }

    // --- Photos composants techniques ---

    public function getPhotoCoffretDeCommande(): ?string
    {
        return $this->photo_coffret_de_commande;
    }

    public function setPhotoCoffretDeCommande(?string $photo_coffret_de_commande): static
    {
        $this->photo_coffret_de_commande = $photo_coffret_de_commande;
        return $this;
    }

    public function getPhotoCarte(): ?string
    {
        return $this->photo_carte;
    }

    public function setPhotoCarte(?string $photo_carte): static
    {
        $this->photo_carte = $photo_carte;
        return $this;
    }

    public function getPhotoRail(): ?string
    {
        return $this->photo_rail;
    }

    public function setPhotoRail(?string $photo_rail): static
    {
        $this->photo_rail = $photo_rail;
        return $this;
    }

    public function getPhotoEquerreRail(): ?string
    {
        return $this->photo_equerre_rail;
    }

    public function setPhotoEquerreRail(?string $photo_equerre_rail): static
    {
        $this->photo_equerre_rail = $photo_equerre_rail;
        return $this;
    }

    public function getPhotoFixationCoulisse(): ?string
    {
        return $this->photo_fixation_coulisse;
    }

    public function setPhotoFixationCoulisse(?string $photo_fixation_coulisse): static
    {
        $this->photo_fixation_coulisse = $photo_fixation_coulisse;
        return $this;
    }

    public function getPhotoMoteur(): ?string
    {
        return $this->photo_moteur;
    }

    public function setPhotoMoteur(?string $photo_moteur): static
    {
        $this->photo_moteur = $photo_moteur;
        return $this;
    }

    public function getPhotoAxe(): ?string
    {
        return $this->photo_axe;
    }

    public function setPhotoAxe(?string $photo_axe): static
    {
        $this->photo_axe = $photo_axe;
        return $this;
    }

    public function getPhotoSerrure(): ?string
    {
        return $this->photo_serrure;
    }

    public function setPhotoSerrure(?string $photo_serrure): static
    {
        $this->photo_serrure = $photo_serrure;
        return $this;
    }

    public function getPhotoSerrure1(): ?string
    {
        return $this->photo_serrure1;
    }

    public function setPhotoSerrure1(?string $photo_serrure1): static
    {
        $this->photo_serrure1 = $photo_serrure1;
        return $this;
    }

    // --- Photos éléments spécifiques ---

    public function getPhotoBache(): ?string
    {
        return $this->photo_bache;
    }

    public function setPhotoBache(?string $photo_bache): static
    {
        $this->photo_bache = $photo_bache;
        return $this;
    }

    public function getPhotoJoue(): ?string
    {
        return $this->photo_joue;
    }

    public function setPhotoJoue(?string $photo_joue): static
    {
        $this->photo_joue = $photo_joue;
        return $this;
    }

    public function getPhotoButoir(): ?string
    {
        return $this->photo_butoir;
    }

    public function setPhotoButoir(?string $photo_butoir): static
    {
        $this->photo_butoir = $photo_butoir;
        return $this;
    }

    public function getPhotoVantail(): ?string
    {
        return $this->photo_vantail;
    }

    public function setPhotoVantail(?string $photo_vantail): static
    {
        $this->photo_vantail = $photo_vantail;
        return $this;
    }

    public function getPhotoLinteau(): ?string
    {
        return $this->photo_linteau;
    }

    public function setPhotoLinteau(?string $photo_linteau): static
    {
        $this->photo_linteau = $photo_linteau;
        return $this;
    }

    public function getPhotoBarriere(): ?string
    {
        return $this->photo_barriere;
    }

    public function setPhotoBarriere(?string $photo_barriere): static
    {
        $this->photo_barriere = $photo_barriere;
        return $this;
    }

    public function getPhotoTourniquet(): ?string
    {
        return $this->photo_tourniquet;
    }

    public function setPhotoTourniquet(?string $photo_tourniquet): static
    {
        $this->photo_tourniquet = $photo_tourniquet;
        return $this;
    }

    public function getPhotoSas(): ?string
    {
        return $this->photo_sas;
    }

    public function setPhotoSas(?string $photo_sas): static
    {
        $this->photo_sas = $photo_sas;
        return $this;
    }

    public function getPhotoFeux(): ?string
    {
        return $this->photo_feux;
    }

    public function setPhotoFeux(?string $photo_feux): static
    {
        $this->photo_feux = $photo_feux;
        return $this;
    }

    // --- Photos marquage au sol ---

    public function getPhotoMarquageAuSol(): ?string
    {
        return $this->photo_marquage_au_sol;
    }

    public function setPhotoMarquageAuSol(?string $photo_marquage_au_sol): static
    {
        $this->photo_marquage_au_sol = $photo_marquage_au_sol;
        return $this;
    }

    public function getPhotoMarquageAuSol_(): ?string
    {
        return $this->photo_marquage_au_sol_;
    }

    public function setPhotoMarquageAuSol_(?string $photo_marquage_au_sol_): static
    {
        $this->photo_marquage_au_sol_ = $photo_marquage_au_sol_;
        return $this;
    }

    public function getPhotoMarquageAuSol2(): ?string
    {
        return $this->photo_marquage_au_sol_2;
    }

    public function setPhotoMarquageAuSol2(?string $photo_marquage_au_sol_2): static
    {
        $this->photo_marquage_au_sol_2 = $photo_marquage_au_sol_2;
        return $this;
    }

    // ========== MÉTHODES UTILITAIRES ==========

    /**
     * Retourne toutes les photos non-nulles sous forme de tableau associatif
     * Utile pour l'affichage dans les PDF
     */
    public function getAllPhotos(): array
    {
        $photos = [];
        $reflection = new \ReflectionClass($this);
        
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if (str_starts_with($name, 'photo_')) {
                $value = $property->getValue($this);
                if ($value !== null) {
                    $photos[$name] = $value;
                }
            }
        }
        
        return $photos;
    }

    /**
     * Retourne le chemin de stockage local pour les fichiers photo
     */
    public function getStoragePath(): string
    {
        return sprintf(
            'img/%s/%s/%s/%s',
            $this->code_agence ?? 'unknown',
            $this->id_contact ?? 'unknown',
            $this->annee ?? date('Y'),
            $this->visite ?? 'CE1'
        );
    }

    /**
     * Compte le nombre de photos renseignées
     */
    public function countPhotos(): int
    {
        return count($this->getAllPhotos());
    }
}
