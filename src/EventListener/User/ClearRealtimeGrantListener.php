<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Service\Application\RealtimeAuthorization;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * The hub validates the subscribe cookie by itself and is told nothing when a session ends, so a browser keeps
 * receiving what the account is sent until the cookie runs out.
 *
 * `LogoutEvent` is dispatched on the firewall's own dispatcher, so this is wired per firewall in
 * `config/services.yaml` rather than through `#[AsEventListener]`, which cannot name one.
 */
final class ClearRealtimeGrantListener
{
    public function __construct(private readonly RealtimeAuthorization $realtime)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $this->realtime->revoke();
    }
}
