<?php

declare(strict_types=1);

namespace App\EventListener\User;

use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

use function str_contains;
use function strtolower;

/**
 * A prefetch does not set the page to return to after signing in.
 *
 * Turbo fetches a link while the pointer is over it, and Symfony's exception listener stores the address of every
 * page that requires signing in. Without this, the address of the last link the pointer was over replaces the
 * address of the page that was opened. That listener is final, so the stored address is read before it runs and
 * written back afterwards. Setting the redirect stops propagation of the exception event, so the address is
 * written back on the response event.
 */
#[AsEventListener(
    event: ExceptionEvent::class,
    method: 'stash',
    priority: 2,
)]
#[AsEventListener(
    event: ResponseEvent::class,
    method: 'restore',
)]
final readonly class PrefetchTargetPathListener
{
    use TargetPathTrait;

    private const string ATTRIBUTE = '_prefetch_target_path';

    public function __construct(
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
    ) {
    }

    public function stash(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || !self::isPrefetch($request)
        ) {
            return;
        }

        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $firewall) {
            return;
        }

        // Without a session there is nothing to restore, and reading the session would start one.
        $previous = $request->hasPreviousSession()
            ? $this->getTargetPath(
                $request->getSession(),
                $firewall,
            )
            : null;

        $request->attributes->set(
            self::ATTRIBUTE,
            [
                $firewall,
                $previous,
            ],
        );
    }

    public function restore(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (
            !$event->isMainRequest()
            || !$request->attributes->has(self::ATTRIBUTE)
        ) {
            return;
        }

        [
            $firewall,
            $previous,
        ] = $request->attributes->get(self::ATTRIBUTE);
        if (
            !$request->hasSession()
            || !$request->getSession()->isStarted()
        ) {
            return;
        }

        $session = $request->getSession();
        if (
            $previous === $this->getTargetPath(
                $session,
                $firewall,
            )
        ) {
            return;
        }

        if (null === $previous) {
            $this->removeTargetPath(
                $session,
                $firewall,
            );

            return;
        }

        $this->saveTargetPath(
            $session,
            $firewall,
            $previous,
        );
    }

    /**
     * Turbo sends `X-Sec-Purpose`; a browser's own speculative fetch sends `Sec-Purpose`.
     */
    private static function isPrefetch(Request $request): bool
    {
        foreach (
            [
                'X-Sec-Purpose',
                'Sec-Purpose',
            ] as $header
        ) {
            if (
                str_contains(
                    strtolower($request->headers->get($header) ?? ''),
                    'prefetch',
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
