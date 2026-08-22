<?php

declare(strict_types=1);

namespace App\EventListener\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function preg_replace;
use function str_contains;

#[AsEventListener(
    event: KernelEvents::RESPONSE,
    priority: -256,
)]
final readonly class DocumentationCspListener
{
    private const string PATH = '/api-docs';

    private const array HEADERS = [
        'Content-Security-Policy',
        'Content-Security-Policy-Report-Only',
    ];

    public function __invoke(ResponseEvent $event): void
    {
        if (self::PATH !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        foreach (self::HEADERS as $header) {
            $policy = $headers->get($header);

            if (
                null === $policy
                || !str_contains(
                    $policy,
                    'strict-dynamic',
                )
            ) {
                continue;
            }

            $headers->set(
                $header,
                (string) preg_replace(
                    '/\s*\'strict-dynamic\'/',
                    '',
                    $policy,
                ),
            );
        }
    }
}
