<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Service\User\KnownDeviceRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Attaches the device cookie {@see KnownDeviceRegistry::recognise()} left on the request. Recognition runs inside the
 * remember-me handler, which holds no response; Symfony's own remember-me cookie rides the same relay, but its request
 * attribute carries exactly one cookie, so this one has an attribute and a listener of its own.
 */
#[AsEventListener(event: ResponseEvent::class)]
final readonly class KnownDeviceCookieListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $cookie = $event->getRequest()->attributes->get(KnownDeviceRegistry::COOKIE_ATTRIBUTE);

        if (!$cookie instanceof Cookie) {
            return;
        }

        $event->getResponse()->headers->setCookie($cookie);
    }
}
