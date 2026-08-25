<?php

declare(strict_types=1);

namespace App\EventListener\Database;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\Database\RegisterNetworkChecker;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_intersect;
use function assert;

/**
 * Explains a register denial to somebody who holds the office but is sitting off the network.
 *
 * The bare 403 reads as "your account cannot do this" when the truth is "not from here", and the register's links are
 * already absent from their menus, so the denial is otherwise unexplained.
 *
 * Priority 8: below {@see \App\EventListener\User\SudoAccessDeniedListener} (10) and
 * {@see \App\EventListener\User\MfaEnforcementListener} (9), which name an action that can still be taken, and
 * above Symfony's per-firewall `ExceptionListener` (1), which would send a remember-me session back to the login form
 * where signing in again changes nothing.
 */
#[AsEventListener(
    event: ExceptionEvent::class,
    priority: 8,
)]
final readonly class RegisterNetworkAccessDeniedListener
{
    private const array REGISTER_ATTRIBUTES = [
        UserRoles::DatabaseAdmin->value,
        UserRoles::DatabaseReadOnly->value,
    ];

    public function __construct(
        private RegisterNetworkChecker $networkChecker,
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $accessDenied = $this->findAccessDenied($event->getThrowable());
        if (null === $accessDenied) {
            return;
        }

        if (
            [] === array_intersect(
                self::REGISTER_ATTRIBUTES,
                $accessDenied->getAttributes(),
            )
        ) {
            return;
        }

        if ($this->networkChecker->allowsCurrentRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        // The roles before the restriction takes them away: somebody who never held one gets the ordinary answer.
        if (
            [] === array_intersect(
                self::REGISTER_ATTRIBUTES,
                $user->getRoles(),
            )
        ) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        assert($session instanceof Session);
        $session->getFlashBag()->add(
            AlertTypes::Warning->value,
            $this->translator->trans(
                'The register can only be read and changed from the association\'s own network. Everything else on your account is unaffected.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
            ),
        );

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('frontpage/index'),
        ));
        $event->stopPropagation();
    }

    private function findAccessDenied(?Throwable $throwable): ?AccessDeniedException
    {
        while (null !== $throwable) {
            if ($throwable instanceof AccessDeniedException) {
                return $throwable;
            }

            $throwable = $throwable->getPrevious();
        }

        return null;
    }
}
