<?php

namespace App\Security;

use App\Repository\ContratCadreRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Redirection post-login selon le rôle de l'utilisateur
 * 
 * - ROLE_ADMIN / ROLE_ADMIN_AGENCE → Dashboard (app_home)
 * - ROLE_CC_ADMIN → Dashboard (accès à tous les CC via menu)
 * - {SLUG}_ADMIN / {SLUG}_USER → /cc/{slug}
 * - Défaut → app_home
 */
class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ContratCadreRepository $contratCadreRepository
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $user = $token->getUser();
        $roles = $user->getRoles();

        // 1. Admin global ou CC Admin → Dashboard
        if (in_array('ROLE_ADMIN', $roles) || in_array('ROLE_CC_ADMIN', $roles) || in_array('ROLE_ADMIN_AGENCE', $roles)) {
            return new RedirectResponse($this->router->generate('app_home'));
        }

        // 2. Utilisateur CC spécifique → Redirection vers son portail
        // Les rôles CC dynamiques suivent le pattern: {SLUG}_ADMIN ou {SLUG}_USER
        // Ex: MONDIAL-RELAY_ADMIN, MONDIAL-RELAY_USER, KUEHNE_ADMIN, XPO_USER
        foreach ($roles as $role) {
            // Ignorer les rôles système Symfony
            if (str_starts_with($role, 'ROLE_')) {
                continue;
            }

            // Pattern: tout ce qui finit par _ADMIN ou _USER
            if (preg_match('/^(.+)_(ADMIN|USER)$/', $role, $matches)) {
                $slugFromRole = strtolower($matches[1]);
                
                // Vérifier que le CC existe et est actif
                $cc = $this->contratCadreRepository->findOneBy([
                    'slug' => $slugFromRole,
                    'isActive' => true
                ]);
                
                if ($cc) {
                    return new RedirectResponse(
                        $this->router->generate('app_contrat_cadre_sites', ['slug' => $cc->getSlug()])
                    );
                }
            }
        }

        // 3. Défaut → Home
        return new RedirectResponse($this->router->generate('app_home'));
    }
}
