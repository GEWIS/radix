<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\SudoArea;
use App\Security\User\SudoComponents;
use App\Security\User\SudoMode;
use App\Service\Application\LiveComponentAction;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Extends a sudo grant for a guarded write.
 *
 * A grant expired a fixed half hour after the confirmation, regardless of activity, which interrupted long edits: the
 * expiration passed while the form was being filled in and the submission was then refused.
 *
 * Only a write extends it. A GET does not, and neither do the two edit-lock endpoints, because both are sent on a
 * timer rather than by user interaction.
 *
 * A live component is served outside the addresses {@see SudoArea} names, so a write to one extends a grant only
 * where the component requires the grant itself ({@see SudoComponents}). Every other component writes on pages the
 * grant has nothing to do with.
 *
 * Priority 6 places this below {@see SudoEnforcementListener} at 7, so a request without a grant has already been
 * refused before this runs.
 */
#[AsEventListener(
    event: RequestEvent::class,
    priority: 6,
)]
final readonly class SudoRefreshListener
{
    public function __construct(
        private SudoMode $sudoMode,
        private SudoArea $area,
        private SudoComponents $sudoComponents,
        private LiveComponentAction $liveComponentAction,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // A safe method is not a write, so it does not extend the expiration.
        if ($request->isMethodSafe()) {
            return;
        }

        if (!$this->isGuardedWrite($request)) {
            return;
        }

        $this->sudoMode->touch();
    }

    private function isGuardedWrite(Request $request): bool
    {
        // A live component sends every action as a POST, so the method does not indicate whether the request writes.
        // Undecided does not extend the expiration, which is the opposite of what maintenance does with the same
        // answer ({@see LiveComponentAction}).
        if ($this->liveComponentAction->isRequest($request)) {
            return true === $this->liveComponentAction->writes($request)
                && $this->sudoComponents->covers($request);
        }

        return $this->area->covers($request)
            && !$this->area->isKeepalive($request);
    }
}
