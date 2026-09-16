<?php

declare(strict_types=1);

namespace App\EventListener\User;

use App\Security\User\SudoVoter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function implode;
use function preg_match;
use function sprintf;

/**
 * Requires a live sudo grant on every address in {@see self::AREAS}, rather than on the actions a developer
 * remembered to mark.
 *
 * Priority 7 is just below the firewall (8), so `access_control` decides the roles first and a user who may not have
 * access at all is sent to the login page instead of a password prompt. {@see SudoAccessDeniedListener} turns the
 * denial into the redirect to the confirmation form.
 *
 * Signing in, resetting a password and confirming sudo are served under `/user` and `/company` but outside
 * `/security`, which is what lets a user without a grant obtain one. Live components are served under `/_components`
 * and declare `#[IsGranted(SudoVoter::ATTRIBUTE)]` themselves.
 */
#[AsEventListener(
    event: RequestEvent::class,
    priority: 7,
)]
final class SudoEnforcementListener
{
    private const array AREAS = [
        'admin',
        'user/settings',
        'user/security',
        'company/security',
    ];

    private readonly string $pattern;

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        #[Autowire('%app.locales%')]
        string $locales,
    ) {
        $this->pattern = sprintf(
            '{^/(?:%s)/(?:%s)(?:/|$)}',
            $locales,
            implode(
                '|',
                self::AREAS,
            ),
        );
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (
            1 !== preg_match(
                $this->pattern,
                $event->getRequest()->getPathInfo(),
            )
        ) {
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
