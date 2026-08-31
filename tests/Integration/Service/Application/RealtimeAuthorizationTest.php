<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Application;

use App\Service\Application\RealtimeAuthorization;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Which Mercure topics a page may subscribe to decides what the hub is allowed to push at whoever is holding the
 * browser, so this runs over the real firewall map and the real voters rather than a stand-in for them.
 */
final class RealtimeAuthorizationTest extends KernelTestCase
{
    public function testAPasserByOnlyGetsTheBroadcastTopic(): void
    {
        self::assertSame(
            ['gewis/public'],
            $this->topicsFor(null),
        );
    }

    public function testSomeoneSignedInAlsoGetsTheirOwnTopicAndTheMembersOne(): void
    {
        self::assertSame(
            [
                'gewis/public',
                'gewis/members',
                'gewis/user/main/8025',
            ],
            $this->topicsFor(new UsernamePasswordToken(
                $this->member(),
                'main',
                ['ROLE_USER'],
            )),
        );
    }

    /**
     * A company user is signed in but is not a member, so what every member is shown at once is not theirs to
     * receive.
     */
    public function testACompanyUserDoesNotGetTheMembersTopic(): void
    {
        self::assertNotContains(
            'gewis/members',
            $this->topicsFor(new UsernamePasswordToken(
                $this->member(),
                'main',
                ['ROLE_COMPANY_USER'],
            )),
        );
    }

    public function testAPasserByDoesNotGetTheMembersTopic(): void
    {
        self::assertNotContains(
            'gewis/members',
            $this->topicsFor(null),
        );
    }

    /**
     * A sign-in still waiting on its second factor has the member on the token but has not signed in yet. Handing it
     * their topic would push their notifications to whoever holds the password.
     */
    public function testASignInWaitingOnItsSecondFactorGetsNoTopicOfItsOwn(): void
    {
        self::assertSame(
            ['gewis/public'],
            $this->topicsFor(new TwoFactorToken(
                new UsernamePasswordToken(
                    $this->member(),
                    'main',
                    ['ROLE_USER'],
                ),
                null,
                'main',
                ['totp'],
            )),
        );
    }

    /**
     * A browser holds one authorization cookie whatever page minted it, so what it grants cannot be allowed to depend
     * on which page did. The topics the shared connection subscribes to stay the narrower list.
     */
    public function testTheBoardIsGrantedThePagesItWatchesFromWhicheverPageItIsOn(): void
    {
        $token = new UsernamePasswordToken(
            $this->member(),
            'main',
            ['ROLE_BOARD'],
        );

        self::assertSame(
            [
                'gewis/public',
                'gewis/members',
                'gewis/user/main/8025',
                'photo/album/{album}/cover',
                'frontpage/page-images/{page}',
                'frontpage/page-images/pending/{run}',
            ],
            $this->grantsFor($token),
        );
        self::assertSame(
            [
                'gewis/public',
                'gewis/members',
                'gewis/user/main/8025',
            ],
            $this->topicsFor($token),
        );
    }

    public function testAMemberIsGrantedNothingBeyondWhatTheyListenTo(): void
    {
        $token = new UsernamePasswordToken(
            $this->member(),
            'main',
            ['ROLE_USER'],
        );

        self::assertSame(
            $this->topicsFor($token),
            $this->grantsFor($token),
        );
    }

    /**
     * @return string[]
     */
    private function grantsFor(?TokenInterface $token): array
    {
        return $this->authorizationFor($token)->grants();
    }

    /**
     * @return string[]
     */
    private function topicsFor(?TokenInterface $token): array
    {
        return $this->authorizationFor($token)->topics();
    }

    private function authorizationFor(?TokenInterface $token): RealtimeAuthorization
    {
        self::bootKernel();

        self::getContainer()->get('request_stack')->push(Request::create('/en/'));
        self::getContainer()->get('security.token_storage')->setToken($token);

        $realtime = self::getContainer()->get(RealtimeAuthorization::class);
        self::assertInstanceOf(
            RealtimeAuthorization::class,
            $realtime,
        );

        return $realtime;
    }

    private function member(): InMemoryUser
    {
        return new InMemoryUser(
            '8025',
            null,
        );
    }
}
