<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Database\Enums\InstallationFunctions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The pages that belong to the application itself rather than to any of its domains: the reference list of the
 * functions someone can be installed in.
 */
final class ApplicationController extends AbstractController
{
    #[Route(
        path: '/bodies/functions',
        name: 'decision_organ_functions',
        methods: ['GET'],
    )]
    public function functions(): Response
    {
        return $this->render(
            'database/decision/organ/functions.html.twig',
            [
                'current_functions' => InstallationFunctions::currentCases(),
                'legacy_functions' => InstallationFunctions::legacyCases(),
                'administrative_functions' => InstallationFunctions::administrativeCases(),
            ],
        );
    }
}
