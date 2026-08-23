<?php

declare(strict_types=1);

namespace App\EventListener\Api;

use App\Security\Api\ApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function max;
use function str_starts_with;
use function time;

#[AsEventListener(
    event: KernelEvents::REQUEST,
    priority: 6,
)]
final readonly class RateLimitListener
{
    private const string API_PREFIX = '/api';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'limiter.api_principal')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (
            !$event->isMainRequest()
            || !str_starts_with(
                $event->getRequest()->getPathInfo(),
                self::API_PREFIX,
            )
        ) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (!($token instanceof ApiToken)) {
            return;
        }

        $limit = $this->limiter
            ->create('api-principal-' . $token->getApiPrincipal()->getId())
            ->consume();

        if ($limit->isAccepted()) {
            return;
        }

        throw new TooManyRequestsHttpException(
            max(
                1,
                $limit->getRetryAfter()->getTimestamp() - time(),
            ),
            'Too many requests. Slow down.',
        );
    }
}
