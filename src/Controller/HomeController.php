<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Client Contrat Cadre -> redirection vers son espace
        if ($user && $user->isContratCadreUser() && $user->getContratCadre()) {
            return $this->redirectToRoute('app_contrat_cadre', [
                'slug' => $user->getContratCadre()->getSlug(),
            ]);
        }

        // Utilisateur multi-agences -> choix de l'agence
        if ($user && $user->isMultiAgency()) {
            return $this->render('home/select_agency.html.twig', [
                'agencies' => $user->getAgencies(),
            ]);
        }

        // Utilisateur mono-agence -> redirection vers la liste clients
        $agencies = $user ? $user->getAgencies() : [];
        if (!empty($agencies)) {
            return $this->redirectToRoute('app_clients_list', [
                'agencyCode' => $agencies[0],
            ]);
        }

        // Fallback
        return $this->render('home/index.html.twig');
    }

    #[Route('/agency/{agencyCode}', name: 'app_clients_list')]
    public function clientsList(string $agencyCode): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user && !$user->hasAccessToAgency($agencyCode)) {
            throw $this->createAccessDeniedException('Acces non autorise a cette agence');
        }

        return $this->render('clients/list.html.twig', [
            'agencyCode' => $agencyCode,
        ]);
    }
}
