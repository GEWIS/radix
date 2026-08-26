<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Application;

use App\Entity\Application\Enums\ImageVariant;
use App\Message\Application\PregenerateImageVariantMessage;
use App\Service\Application\TransportStatusProvider;
use App\ViewModel\Application\TransportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function sprintf;

final class TransportStatusProviderTest extends KernelTestCase
{
    public function testEveryConfiguredTransportIsListedOnceUnderItsBareName(): void
    {
        self::bootKernel();

        $names = array_column(
            self::getContainer()->get(TransportStatusProvider::class)->transports(),
            'name',
        );

        // The bare name, not `messenger.transport.images`, which the locator also answers to.
        self::assertContains(
            'images',
            $names,
        );
        self::assertNotContains(
            'messenger.transport.images',
            $names,
        );
        self::assertSame(
            $names,
            array_values(array_unique($names)),
        );
    }

    public function testATransportThatCannotBeCountedReportsUnknownRatherThanEmpty(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(MessageBusInterface::class)->dispatch(new PregenerateImageVariantMessage(
            'photos/albums/1/example.jpg',
            ImageVariant::W320,
        ));

        // Under test every queue is an `InMemoryTransport`, which is not `MessageCountAwareInterface`; production's
        // AMQP and Doctrine transports both are. The message just dispatched is therefore invisible here, and the
        // point of the assertion is that this reads as "unknown" rather than as an empty queue.
        self::assertNull($this->transport('images')->waiting);
    }

    public function testTheFailureTransportIsMarkedAndSortedLast(): void
    {
        self::bootKernel();

        $transports = self::getContainer()->get(TransportStatusProvider::class)->transports();

        $failure = array_values(array_filter(
            $transports,
            static fn (TransportStatus $status): bool => $status->isFailureTransport,
        ));

        self::assertCount(
            1,
            $failure,
        );
        self::assertSame(
            'failed',
            $failure[0]->name,
        );
        self::assertTrue($transports[count($transports) - 1]->isFailureTransport);
    }

    public function testTheFailedListIsEmptyAndUntruncatedWhenNothingHasFailed(): void
    {
        self::bootKernel();

        // The failure transport is the one transport the test environment leaves on Doctrine, so this also covers
        // the page surviving a transport it cannot reach: it reports nothing rather than throwing.
        $list = self::getContainer()->get(TransportStatusProvider::class)->failed();

        self::assertSame(
            [],
            $list->rows,
        );
        self::assertFalse($list->truncated);
    }

    private function transport(string $name): TransportStatus
    {
        foreach (self::getContainer()->get(TransportStatusProvider::class)->transports() as $status) {
            if ($status->name !== $name) {
                continue;
            }

            return $status;
        }

        self::fail(sprintf('No transport named "%s".', $name));
    }
}
