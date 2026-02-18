<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO pour la création/édition d'un client (contact).
 * 
 * Sert d'intermédiaire entre le formulaire et l'insertion DBAL
 * dans la table contact_sXX de l'agence cible.
 * Non lié à une entité Doctrine spécifique (13 classes ContactSXX séparées).
 */
class ContactDTO
{
    // ──────────────────────────────────────────────
    // Identité client
    // ──────────────────────────────────────────────

    #[Assert\NotBlank(message: 'La raison sociale est obligatoire.')]
    #[Assert\Length(max: 255, maxMessage: 'La raison sociale ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $raisonSociale = null;

    #[Assert\Length(max: 255)]
    public ?string $nom = null;

    #[Assert\Length(max: 255)]
    public ?string $prenom = null;

    #[Assert\Length(max: 255)]
    public ?string $idSociete = null;

    // ──────────────────────────────────────────────
    // Identifiant Kizeo (saisi manuellement)
    // ──────────────────────────────────────────────

    #[Assert\Length(max: 255, maxMessage: 'L\'identifiant contact ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $idContact = null;

    // ──────────────────────────────────────────────
    // Adresse
    // ──────────────────────────────────────────────

    #[Assert\NotBlank(message: 'L\'adresse est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $adressep1 = null;

    #[Assert\Length(max: 255)]
    public ?string $adressep2 = null;

    #[Assert\NotBlank(message: 'Le code postal est obligatoire.')]
    #[Assert\Length(max: 10, maxMessage: 'Le code postal ne peut pas dépasser {{ limit }} caractères.')]
    #[Assert\Regex(
        pattern: '/^\d{5}$/',
        message: 'Le code postal doit comporter 5 chiffres.'
    )]
    public ?string $cpostalp = null;

    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $villep = null;

    // ──────────────────────────────────────────────
    // Contact
    // ──────────────────────────────────────────────

    #[Assert\Length(max: 255)]
    public ?string $telephone = null;

    #[Assert\Length(max: 255)]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide.')]
    public ?string $email = null;

    #[Assert\Length(max: 255)]
    public ?string $contactSite = null;

    // ──────────────────────────────────────────────
    // Bancaire
    // ──────────────────────────────────────────────

    #[Assert\Length(max: 255)]
    public ?string $rib = null;

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Convertit le DTO en tableau associatif pour insertion DBAL.
     * Les clés correspondent aux colonnes de la table contact_sXX.
     */
    public function toArray(): array
    {
        return [
            'raison_sociale' => $this->raisonSociale,
            'nom'            => $this->nom,
            'prenom'         => $this->prenom,
            'id_societe'     => $this->idSociete,
            'id_contact'     => $this->idContact,
            'adressep_1'     => $this->adressep1,
            'adressep_2'     => $this->adressep2,
            'cpostalp'       => $this->cpostalp,
            'villep'         => $this->villep,
            'telephone'      => $this->telephone,
            'email'          => $this->email,
            'contact_site'   => $this->contactSite,
            'rib'            => $this->rib,
        ];
    }

    /**
     * Crée un DTO à partir d'un tableau (pour l'édition).
     * Accepte les colonnes BDD telles quelles.
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->raisonSociale = $data['raison_sociale'] ?? null;
        $dto->nom           = $data['nom'] ?? null;
        $dto->prenom        = $data['prenom'] ?? null;
        $dto->idSociete     = $data['id_societe'] ?? null;
        $dto->idContact     = $data['id_contact'] ?? null;
        $dto->adressep1     = $data['adressep_1'] ?? null;
        $dto->adressep2     = $data['adressep_2'] ?? null;
        $dto->cpostalp      = $data['cpostalp'] ?? null;
        $dto->villep        = $data['villep'] ?? null;
        $dto->telephone     = $data['telephone'] ?? null;
        $dto->email         = $data['email'] ?? null;
        $dto->contactSite   = $data['contact_site'] ?? null;
        $dto->rib           = $data['rib'] ?? null;

        return $dto;
    }
}
