<?php

declare(strict_types=1);

namespace App\EventListener\Application;

use App\Service\Application\LocalePreference;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * After the router, because `_locale` is a route parameter.
 */
#[AsEventListener(
    event: KernelEvents::REQUEST,
    priority: 15,
)]
final readonly class RememberLocaleListener
{
    public function __construct(
        private LocalePreference $localePreference,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->localePreference->remember($event->getRequest());
    }
}
