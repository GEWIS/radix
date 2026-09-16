<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\SudoArea;
use App\Security\User\SudoVoter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Requires a live sudo grant on every address {@see SudoArea} names, rather than on the actions a developer
 * remembered to mark.
 *
 * Priority 7 is just below the firewall (8), so `access_control` decides the roles first and a user who may not have
 * access at all is sent to the login page instead of a password prompt. {@see SudoAccessDeniedListener} turns the
 * denial into the redirect to the confirmation form.
 *
 * Live components are served under `/_components` and declare `#[IsGranted(SudoVoter::ATTRIBUTE)]` themselves.
 */
#[AsEventListener(
    event: RequestEvent::class,
    priority: 7,
)]
final class SudoEnforcementListener
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SudoArea $area,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->area->covers($event->getRequest())) {
            return;
        }

        if ($this->authorizationChecker->isGranted(SudoVoter::ATTRIBUTE)) {
            return;
        }

        $exception = new AccessDeniedException('This part of the site is behind sudo.');
        $exception->setAttributes(SudoVoter::ATTRIBUTE);

        throw $exception;
    }
}
