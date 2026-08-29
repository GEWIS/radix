<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Service\Application\LocalePreference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_replace;

/**
 * Sends an address that carries no language to the same page under one that does, for the addresses we do not get to
 * move: links already sent, return addresses travelling with an open checkout session, and whatever an external
 * application is configured with.
 *
 * A 302 rather than a 301, because where it lands depends on `Accept-Language`.
 */
final class LocaleRedirectController extends AbstractController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LocalePreference $localePreference,
    ) {
    }

    public function __invoke(
        Request $request,
        string $route,
    ): RedirectResponse {
        /** @var array<string, mixed> $parameters */
        $parameters = $request->attributes->get(
            '_route_params',
            [],
        );
        // Where to go is configuration, not a parameter to pass on to it.
        unset($parameters['route']);

        $parameters['_locale'] = $this->localePreference->resolve($request);

        return $this->redirect($this->urlGenerator->generate(
            $route,
            // The checkout return addresses carry the Stripe session id the page reads.
            array_replace(
                $request->query->all(),
                $parameters,
            ),
        ));
    }
}
