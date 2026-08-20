<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Database\Enums\InstallationFunctions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function explode;
use function in_array;
use function ltrim;

/**
 * The pages that belong to the application itself rather than to any of its domains: the language switch, and the
 * reference list of the functions someone can be installed in.
 */
final class ApplicationController extends AbstractController
{
    /**
     * @param string[] $enabledLocales
     */
    public function __construct(
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * Switch the interface language.
     *
     * `application_lang` is declared in config/routes.yaml rather than here: the pages that reach it carry no language
     * in their address, so it cannot answer under the prefix this controller is imported with.
     */
    public function lang(
        Request $request,
        string $lang,
    ): Response {
        $request->getSession()->set(
            '_locale',
            in_array(
                $lang,
                $this->enabledLocales,
                true,
            ) ? $lang : $this->defaultLocale,
        );

        // Return to the page the switch was made on, reduced to its path so that the referer cannot send the
        // visitor to another site. A leading slash of its own would make the result protocol-relative, which is
        // such an address again, so it is dropped.
        $referer = explode(
            '/',
            (string) $request->headers->get('referer'),
            4,
        );
        if (isset($referer[3])) {
            return $this->redirect('/' . ltrim($referer[3], '/'));
        }

        // Without a referer there is no page to return to. Anyone who is not logged in reached this from the
        // enrolment form, which is the only page they can see.
        if (null === $this->getUser()) {
            return $this->redirectToRoute('join_index');
        }

        return $this->redirectToRoute('admin/index');
    }

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
