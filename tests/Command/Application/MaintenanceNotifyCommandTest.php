<?php

declare(strict_types=1);

namespace App\Tests\Command\Application;

use App\Command\Application\MaintenanceNotifyCommand;
use App\Service\Application\RealtimeNotifier;
use App\Service\Application\RealtimeTopics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The command publishes one announcement that tells every connected client to reload, so the maintenance page
 * replaces a form whose submission would be lost. It publishes on the public topic, because a signed-out visitor
 * is affected too.
 */
#[CoversClass(MaintenanceNotifyCommand::class)]
final class MaintenanceNotifyCommandTest extends TestCase
{
    /** @var list<Update> */
    private array $updates = [];

    public function testEveryConnectedClientIsAskedToReload(): void
    {
        $tester = new CommandTester(new MaintenanceNotifyCommand($this->notifier()));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );

        self::assertCount(
            1,
            $this->updates,
        );
        $update = $this->updates[0];
        self::assertSame(
            [RealtimeTopics::PUBLIC],
            $update->getTopics(),
        );
        self::assertFalse(
            $update->isPrivate(),
            'A visitor who is not signed in is in the way just as much as a member is.',
        );
        self::assertSame(
            ['type' => 'force_reload'],
            json_decode(
                $update->getData(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertStringContainsString(
            'Asked every connected client to reload.',
            $tester->getDisplay(),
        );
    }

    private function notifier(): RealtimeNotifier
    {
        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(
            function (Update $update): string {
                $this->updates[] = $update;

                return '';
            },
        );

        return new RealtimeNotifier(
            $hub,
            new RealtimeTopics('a secret that is only ever this test\'s'),
        );
    }
}
