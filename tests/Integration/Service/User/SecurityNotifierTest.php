<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\Application\Enums\NotificationType;
use App\Message\User\SecurityNotificationMessage;
use App\Security\User\Firewall;
use App\Security\User\RequestOrigin;
use App\Service\User\SecurityNotifier;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The notice is raised after the action it describes has already happened, so a broker that is down may only be logged:
 * it must never turn a completed sign-in or password change into an error the member sees.
 */
final class SecurityNotifierTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheNoticeCarriesTheFirewallTheAccountAndWhereItCameFrom(): void
    {
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (SecurityNotificationMessage $message) use (&$dispatched) {
                $dispatched = $message;

                return new Envelope($message);
            });

        $notifier = new SecurityNotifier(
            $bus,
            $this->origin(),
            self::createStub(LoggerInterface::class),
        );

        $notifier->notify(
            Firewall::Company,
            'recruitment@nexunt.example.com',
            NotificationType::SignIn,
            $this->request(),
        );

        self::assertInstanceOf(
            SecurityNotificationMessage::class,
            $dispatched,
        );
        self::assertSame(
            'company',
            $dispatched->getFirewallName(),
        );
        self::assertSame(
            'recruitment@nexunt.example.com',
            $dispatched->getUserIdentifier(),
        );
        self::assertSame(
            NotificationType::SignIn,
            $dispatched->getType(),
        );
        // The request names a browser and an address, which is what the notice shows the account owner.
        $origin = $dispatched->getOrigin();
        self::assertArrayHasKey(
            'browser',
            $origin,
        );
        self::assertStringStartsWith(
            'Firefox',
            $origin['browser'],
        );
        self::assertArrayHasKey(
            'address',
            $origin,
        );
        self::assertSame(
            '192.0.2.10',
            $origin['address'],
        );
    }

    public function testAFailingBrokerIsLoggedRatherThanRaised(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('broker is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Could not raise a security notice.',
                self::arrayHasKey('exception'),
            );

        $notifier = new SecurityNotifier(
            $bus,
            $this->origin(),
            $logger,
        );

        // No exception escapes: the caller has already performed what is being reported.
        $notifier->notify(
            Firewall::Main,
            '8000',
            NotificationType::PasswordChanged,
            $this->request(),
        );
    }

    private function request(): Request
    {
        return Request::create(
            '/',
            server: [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
                'REMOTE_ADDR' => '192.0.2.10',
            ],
        );
    }

    private function origin(): RequestOrigin
    {
        return self::getContainer()->get(RequestOrigin::class);
    }
}
