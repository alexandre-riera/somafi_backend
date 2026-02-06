<?php

namespace App\Twig;

use App\Repository\ContratCadreRepository;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Extension Twig pour injecter les contrats cadre actifs dans tous les templates
 * Utilisé pour le menu dropdown dans la navbar
 */
class ContratCadreExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ContratCadreRepository $contratCadreRepository
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'contrats_cadre_actifs' => $this->contratCadreRepository->findAllActive(),
        ];
    }
}
