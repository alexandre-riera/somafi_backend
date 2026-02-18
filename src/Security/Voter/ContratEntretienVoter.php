<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter pour les contrats d'entretien (contrat_sXX)
 *
 * Matrice d'accès :
 * ┌──────────────────┬───────┬──────────────┬──────────────────────┬─────────────┐
 * │ Action           │ ADMIN │ ADMIN_AGENCE │ GESTIONNAIRE_CONTRAT │ USER_AGENCE │
 * ├──────────────────┼───────┼──────────────┼──────────────────────┼─────────────┤
 * │ LIST / VIEW      │ ✅ *  │ ✅ agences   │ ✅ agences           │ ✅ agences  │
 * │ CREATE / EDIT    │ ✅ *  │ ✅ agences   │ ✅ agences           │ ❌          │
 * │ DEACTIVATE       │ ✅ *  │ ✅ agences   │ ✅ agences           │ ❌          │
 * │ DELETE (hard)    │ ✅ *  │ ✅ agences   │ ❌                   │ ❌          │
 * └──────────────────┴───────┴──────────────┴──────────────────────┴─────────────┘
 * * ADMIN = toutes agences
 */
class ContratEntretienVoter extends Voter
{
    // Attributs supportés
    public const LIST = 'CONTRAT_LIST';
    public const VIEW = 'CONTRAT_VIEW';
    public const CREATE = 'CONTRAT_CREATE';
    public const EDIT = 'CONTRAT_EDIT';
    public const DEACTIVATE = 'CONTRAT_DEACTIVATE';
    public const DELETE = 'CONTRAT_DELETE';

    // Groupes d'actions pour lisibilité
    private const READ_ACTIONS = [self::LIST, self::VIEW];
    private const WRITE_ACTIONS = [self::CREATE, self::EDIT, self::DEACTIVATE];
    private const ADMIN_ACTIONS = [self::DELETE];

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Le voter supporte :
        // - LIST/CREATE avec $subject = string (code agence, ex: 'S10')
        // - VIEW/EDIT/DEACTIVATE/DELETE avec $subject = entité ContratSxx ou string (code agence)
        return in_array($attribute, [
            self::LIST,
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DEACTIVATE,
            self::DELETE,
        ]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Utilisateur inactif → accès refusé
        if (!$user->isActive()) {
            return false;
        }

        // ROLE_ADMIN → accès total, toutes agences
        if (in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return true;
        }

        // Résolution du code agence depuis le subject
        $agencyCode = $this->resolveAgencyCode($subject);

        // Si on ne peut pas déterminer l'agence, refus
        if ($agencyCode === null) {
            return false;
        }

        // Vérifier que l'utilisateur a accès à cette agence
        if (!$this->userHasAgency($user, $agencyCode)) {
            return false;
        }

        // --- Vérification par groupe d'action ---

        // READ (LIST, VIEW) → tout utilisateur ayant accès à l'agence
        if (in_array($attribute, self::READ_ACTIONS, true)) {
            return $this->canRead($user);
        }

        // WRITE (CREATE, EDIT, DEACTIVATE) → GESTIONNAIRE_CONTRAT ou supérieur
        if (in_array($attribute, self::WRITE_ACTIONS, true)) {
            return $this->canWrite($user);
        }

        // ADMIN (DELETE hard) → ADMIN_AGENCE ou supérieur
        if (in_array($attribute, self::ADMIN_ACTIONS, true)) {
            return $this->canDelete($user);
        }

        return false;
    }

    /**
     * Lecture : tout rôle agence (USER_AGENCE et supérieur)
     */
    private function canRead(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(User::ROLE_USER_AGENCE, $roles, true)
            || in_array(User::ROLE_GESTIONNAIRE_CONTRAT, $roles, true)
            || in_array(User::ROLE_ADMIN_AGENCE, $roles, true);
    }

    /**
     * Écriture : GESTIONNAIRE_CONTRAT ou ADMIN_AGENCE
     */
    private function canWrite(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(User::ROLE_GESTIONNAIRE_CONTRAT, $roles, true)
            || in_array(User::ROLE_ADMIN_AGENCE, $roles, true);
    }

    /**
     * Suppression hard : ADMIN_AGENCE uniquement
     */
    private function canDelete(User $user): bool
    {
        return in_array(User::ROLE_ADMIN_AGENCE, $user->getRoles(), true);
    }

    /**
     * Vérifie que l'utilisateur a accès à l'agence donnée
     */
    private function userHasAgency(User $user, string $agencyCode): bool
    {
        $agencies = $user->getAgencies() ?? [];

        return in_array($agencyCode, $agencies, true);
    }

    /**
     * Résout le code agence depuis le subject
     *
     * Le subject peut être :
     * - Un string : code agence directement (ex: 'S10')
     * - Un objet entité ContratSxx : on déduit l'agence depuis le FQCN
     * - null : pour les actions globales (non supporté ici)
     */
    private function resolveAgencyCode(mixed $subject): ?string
    {
        // Cas 1 : string → code agence direct
        if (is_string($subject)) {
            return strtoupper($subject);
        }

        // Cas 2 : objet entité → déduction depuis le nom de classe
        // Ex: App\Entity\Agency\ContratS10 → 'S10'
        // Ex: App\Entity\Agency\ContratS100 → 'S100'
        if (is_object($subject)) {
            $className = (new \ReflectionClass($subject))->getShortName();

            // Pattern : Contrat[Avenant]S{code}
            if (preg_match('/(?:Contrat|ContratAvenant)S(\d+)$/', $className, $matches)) {
                return 'S' . $matches[1];
            }
        }

        return null;
    }
}
