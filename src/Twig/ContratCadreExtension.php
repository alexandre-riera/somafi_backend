<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\ContratCadreRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Extension Twig pour injecter les contrats cadre dans la navbar.
 *
 * Nouvelle logique (table user_contrat_cadre) :
 *   - ROLE_ADMIN              → tous les CCs actifs (supervision globale)
 *   - Utilisateur avec CCs    → uniquement ses CCs (admin ou user)
 *   - Autres                  → liste vide
 *
 * La variable injectée reste `contrats_cadre_actifs` pour ne pas
 * casser les templates existants.
 */
class ContratCadreExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ContratCadreRepository $contratCadreRepository,
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        // Non connecté
        if (!$user instanceof User) {
            return ['contrats_cadre_actifs' => []];
        }

        // Admin global SOMAFI → tous les CCs actifs
        if ($user->isAdmin()) {
            return ['contrats_cadre_actifs' => $this->contratCadreRepository->findAllActive()];
        }

        // Autres utilisateurs → uniquement les CCs auxquels ils ont accès
        // (role_type = 'admin' OU 'user' dans user_contrat_cadre)
        $ccs = [];
        foreach ($user->getUserContratCadres() as $ucc) {
            $cc = $ucc->getContratCadre();
            if ($cc->isActive()) {
                // Dédoublonnage par id au cas où (ne devrait pas arriver avec la contrainte unique)
                $ccs[$cc->getId()] = $cc;
            }
        }

        // Tri par nom
        usort($ccs, fn($a, $b) => strcmp($a->getNom(), $b->getNom()));

        return ['contrats_cadre_actifs' => array_values($ccs)];
    }
}