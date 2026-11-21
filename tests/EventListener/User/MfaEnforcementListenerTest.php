<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\EventListener\User\MfaEnforcementListener;
use App\Security\User\MfaPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * A role that requires a second factor is stripped from an account that has not enrolled, so every check naming it
 * fails. That denial is converted into the enrolment page rather than a bare 403.
 */
final class MfaEnforcementListenerTest extends TestCase
{
    public function testAnAdminWithoutASecondFactorIsSentToEnrol(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            'main',
        )($event);

        $response = $event->getResponse();
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            '/en/user/security/mfa',
            $response->getTargetUrl(),
        );
        $session = $request->getSession();
        self::assertInstanceOf(
            Session::class,
            $session,
        );
        self::assertTrue($session->getFlashBag()->has('warning'));
        self::assertTrue($event->isPropagationStopped());
    }

    public function testABoardMemberWithoutASecondFactorIsSentToEnrol(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Board->value),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            'main',
        )($event);

        self::assertNotNull($event->getResponse());
    }

    public function testAnUnrelatedDenialIsLeftToTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial('ROLE_COMPANY_ADMIN'),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            'main',
        )($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    public function testSomethingThatIsNotAnAccessDenialIsLeftAlone(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            new RuntimeException('not a denial'),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            'main',
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testAUserOutsideThePolicyKeepsTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $this->listener(
            $request,
            $this->ordinaryUser(),
            'main',
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testAUserWhoHasEnrolledKeepsTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $user = $this->inScopeUser();
        $user->totpSecret = 'a-secret';

        $this->listener(
            $request,
            $user,
            'main',
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testAnAnonymousRequestKeepsTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $this->listener(
            $request,
            null,
            'main',
        )($event);

        self::assertNull($event->getResponse());
    }

    public function testARequestWithoutAFirewallKeepsTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            null,
        )($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The company portal has no enrolment page of its own, so there is nowhere to send them.
     */
    public function testAFirewallWithoutAnEnrolmentPageKeepsTheOrdinaryPage(): void
    {
        $request = $this->request();
        $event = $this->event(
            $request,
            $this->denial(UserRoles::Admin->value),
        );

        $this->listener(
            $request,
            $this->inScopeUser(),
            'company',
        )($event);

        self::assertNull($event->getResponse());
    }

    private function listener(
        Request $request,
        ?User $user,
        ?string $firewall,
    ): MfaEnforcementListener {
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(
            null === $firewall
                ? null
                : new FirewallConfig(
                    $firewall,
                    'user_checker',
                ),
        );

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/en/user/security/mfa');

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new MfaEnforcementListener(
            new MfaPolicy(true),
            $tokenStorage,
            $urlGenerator,
            $translator,
            $firewallMap,
        );
    }

    private function request(): Request
    {
        $request = Request::create('https://gewis.nl/en/admin');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function event(
        Request $request,
        Throwable $throwable,
    ): ExceptionEvent {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }

    private function denial(string $attribute): AccessDeniedException
    {
        $denial = new AccessDeniedException();
        $denial->setAttributes([$attribute]);

        return $denial;
    }

    /** A board member, which the policy puts in scope whether or not they hold an explicit role. */
    private function inScopeUser(): User
    {
        $member = self::createStub(Member::class);
        $member->method('isBoardMember')->willReturn(true);

        $user = self::createStub(User::class);
        $user->member = $member;
        $user->method('getRoleEntities')->willReturn(new ArrayCollection());
        $user->method('getUserIdentifier')->willReturn('8025');

        return $user;
    }

    private function ordinaryUser(): User
    {
        $member = self::createStub(Member::class);
        $member->method('isBoardMember')->willReturn(false);

        $user = self::createStub(User::class);
        $user->member = $member;
        $user->method('getRoleEntities')->willReturn(new ArrayCollection());
        $user->method('getUserIdentifier')->willReturn('8030');

        return $user;
    }
}
