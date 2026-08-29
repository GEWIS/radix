<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Service\Application\LocalePreference;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(
    event: KernelEvents::REQUEST,
    priority: 160,
)]
final readonly class LocaleRedirectListener
{
    public function __construct(
        private LocalePreference $localePreference,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('/' !== $request->getPathInfo()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            '/' . $this->localePreference->resolve($request) . '/',
        ));
    }
}
