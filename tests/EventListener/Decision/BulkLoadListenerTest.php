<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Decision;

use App\EventListener\Decision\BulkLoadListener;
use App\Service\Decision\ProjectionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Loading fixtures persists a whole register in one flush in no fixed order, so the projection is disabled and
 * rebuilt afterwards.
 */
final class BulkLoadListenerTest extends TestCase
{
    #[DataProvider('bulkCommands')]
    public function testABulkLoadDisablesTheProjection(string $name): void
    {
        $state = new ProjectionState();

        (new BulkLoadListener($state))($this->event($name));

        self::assertFalse($state->isEnabled());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bulkCommands(): iterable
    {
        yield 'the application fixtures' => ['app:fixtures:load'];
        yield 'the doctrine fixtures' => ['doctrine:fixtures:load'];
    }

    public function testAnyOtherCommandLeavesTheProjectionAlone(): void
    {
        $state = new ProjectionState();

        (new BulkLoadListener($state))($this->event('app:decision:generate'));

        self::assertTrue($state->isEnabled());
    }

    private function event(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(
            new Command($name),
            new ArrayInput([]),
            new NullOutput(),
        );
    }
}
