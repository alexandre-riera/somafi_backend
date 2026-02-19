<?php

namespace App\Twig;

use App\Service\ContratEntretienService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class ContratEntretienExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ContratEntretienService $contratService,
        private readonly Security $security,
    ) {}

    public function getGlobals(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return ['contratEntretienAgencies' => []];
        }

        return [
            'contratEntretienAgencies' => $this->contratService->getAccessibleAgencies($user),
        ];
    }
}