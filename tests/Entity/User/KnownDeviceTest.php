<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\KnownDevice;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KnownDevice::class)]
final class KnownDeviceTest extends TestCase
{
    /**
     * A device is exported as the firewall it was seen on and the moments it was first and last seen, not as the
     * identifying values that are kept for matching it.
     */
    public function testTheDeviceExportsWhenAndWhereItWasSeen(): void
    {
        $device = new KnownDevice();
        $device->userIdentifier = 'member-8000';
        $device->firewallName = 'main';
        $device->fingerprint = 'hashed';
        $device->browser = 'Firefox 128';
        $device->operatingSystem = 'Linux';
        $device->firstSeenAt = new DateTimeImmutable('2026-01-01 12:00:00');
        $device->lastSeenAt = new DateTimeImmutable('2026-02-01 12:00:00');

        self::assertSame(
            [
                'firewall' => 'main',
                'browser' => 'Firefox 128',
                'operatingSystem' => 'Linux',
                'firstSeenAt' => '2026-01-01T12:00:00+01:00',
                'lastSeenAt' => '2026-02-01T12:00:00+01:00',
            ],
            $device->toGdprArray(),
        );
    }
}
