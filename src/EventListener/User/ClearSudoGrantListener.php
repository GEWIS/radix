<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\SudoMode;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Drops the sudo grant of the session being signed out.
 *
 * Priority 10 puts this ahead of Symfony's `SessionLogoutListener`, which runs at 0 and invalidates the session. A
 * grant is keyed by session ID, so after the invalidation this would compute the key of the replacement session and
 * leave the real one behind until it expired.
 *
 * `LogoutEvent` is dispatched on the firewall's own dispatcher, so this is wired per firewall in
 * `config/services.yaml` rather than through `#[AsEventListener]`, which cannot name one.
 */
final class ClearSudoGrantListener
{
    public function __construct(private readonly SudoMode $sudoMode)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $this->sudoMode->revoke();
    }
}
