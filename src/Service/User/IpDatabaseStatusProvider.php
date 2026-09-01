<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Security\User\IpNetworkResolver;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function filemtime;
use function min;

/**
 * When the IP databases were last replaced, for the administration dashboard. The file's own modification time is the
 * record: app:user:update-ip-databases only renames a download over the target after it proved sound.
 */
final readonly class IpDatabaseStatusProvider
{
    /**
     * The update runs twice a week, so four days is the widest gap between refreshes; a fifth day means the runs are
     * failing, which the failed messages overview sets out in full.
     */
    private const string OVERDUE = '-5 days';

    private const array DATABASES = [
        IpNetworkResolver::ASN_DATABASE,
        IpNetworkResolver::LOCATION_DATABASE,
    ];

    public function __construct(
        #[Autowire('%app.geoip.directory%')]
        private string $directory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The oldest of the databases speaks for both, because they are refreshed together and the stale one is the
     * problem.
     *
     * @return array{updatedAt: ?DateTimeImmutable, overdue: bool}
     */
    public function status(): array
    {
        $oldest = null;

        foreach (self::DATABASES as $database) {
            $modifiedAt = @filemtime($this->directory . '/' . $database);

            if (false === $modifiedAt) {
                return [
                    'updatedAt' => null,
                    'overdue' => true,
                ];
            }

            $oldest = null === $oldest
                ? $modifiedAt
                : min(
                    $oldest,
                    $modifiedAt,
                );
        }

        $updatedAt = new DateTimeImmutable('@' . $oldest);

        return [
            'updatedAt' => $updatedAt,
            'overdue' => $updatedAt <= $this->clock->now()->modify(self::OVERDUE),
        ];
    }
}
