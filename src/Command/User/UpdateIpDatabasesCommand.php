<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Security\User\IpNetworkResolver;
use MaxMind\Db\Reader;
use Override;
use PharData;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function glob;
use function is_dir;
use function mkdir;
use function rename;
use function sprintf;
use function unlink;

/**
 * Fetches fresh copies of the IP databases {@see IpNetworkResolver} answers from. With MaxMind credentials these are
 * the GeoLite ASN and City editions, which is what puts a city in the security notices; MaxMind caps downloads per
 * day, and two editions twice a week stays far under it. Without credentials they are IPLocate's free files (CC BY-SA
 * 4.0, attribution on the security page), which name networks and countries but no cities. Twice a week is fresh
 * enough; which AS announces an address changes on the timescale of contracts, and a stale answer costs one notice
 * somebody did not need.
 */
#[AsCommand(
    name: 'app:user:update-ip-databases',
    description: 'Fetch fresh copies of the IP databases device recognition and security notices read.',
)]
#[AsCronTask(
    expression: '20 4 * * 1,4',
    jitter: 900,
    transports: 'maintenance',
)]
final class UpdateIpDatabasesCommand extends Command
{
    use HoldsRunLockTrait;

    private const array MAXMIND_EDITIONS = [
        IpNetworkResolver::ASN_DATABASE => 'GeoLite2-ASN',
        IpNetworkResolver::LOCATION_DATABASE => 'GeoLite2-City',
    ];

    private const array IPLOCATE_URLS = [
        IpNetworkResolver::ASN_DATABASE => 'https://media.githubusercontent.com/media/iplocate/ip-address-databases/'
            . 'main/ip-to-asn/ip-to-asn.mmdb',
        IpNetworkResolver::LOCATION_DATABASE => 'https://media.githubusercontent.com/media/iplocate/'
            . 'ip-address-databases/main/ip-to-country/ip-to-country.mmdb',
    ];

    private const string MAXMIND_URL = 'https://download.maxmind.com/geoip/databases/%s/download?suffix=tar.gz';

    /** An address every edition is certain to answer for: the university's own range, which we can vouch for. */
    private const string PROOF_ADDRESS = '131.155.0.1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.geoip.directory%')]
        private readonly string $directory,
        #[Autowire('%env(MAXMIND_ACCOUNT_ID)%')]
        private readonly string $maxmindAccountId,
        #[Autowire('%env(MAXMIND_LICENSE_KEY)%')]
        #[SensitiveParameter]
        private readonly string $maxmindLicenseKey,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        return $this->runExclusively(
            $output,
            fn (): int => $this->executeExclusively(
                $input,
                $output,
            ),
        );
    }

    private function executeExclusively(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle(
            $input,
            $output,
        );

        if (
            !is_dir($this->directory)
            && !@mkdir(
                $this->directory,
                0770,
                true,
            )
        ) {
            $io->error(sprintf(
                'Could not create "%s".',
                $this->directory,
            ));

            return Command::FAILURE;
        }

        $maxmind = '' !== $this->maxmindAccountId && '' !== $this->maxmindLicenseKey;
        $failures = 0;

        foreach (self::MAXMIND_EDITIONS as $database => $edition) {
            $target = $this->directory . '/' . $database;
            $incoming = $target . '.download';

            try {
                if ($maxmind) {
                    $this->downloadMaxMind(
                        $edition,
                        $incoming,
                    );
                } else {
                    $this->download(
                        self::IPLOCATE_URLS[$database],
                        $incoming,
                    );
                }

                $this->prove($incoming);

                // The rename is what keeps a failed or truncated download away from the working copy, and what
                // delivers a fresh file under the running application.
                if (
                    !rename(
                        $incoming,
                        $target,
                    )
                ) {
                    throw new RuntimeException(sprintf(
                        'Could not move the download over "%s".',
                        $target,
                    ));
                }

                $io->writeln(sprintf(
                    'Updated %s from %s.',
                    $database,
                    $maxmind ? $edition : 'IPLocate',
                ));
            } catch (Throwable $e) {
                @unlink($incoming);
                $failures++;

                $io->error(sprintf(
                    'Could not update %s: %s',
                    $database,
                    $e->getMessage(),
                ));
            }
        }

        return 0 === $failures
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * MaxMind serves a tar.gz holding a dated directory with the database inside, so the archive is unpacked beside
     * the target and only the database file is kept.
     */
    private function downloadMaxMind(
        string $edition,
        string $destination,
    ): void {
        $archive = $destination . '.tar.gz';
        $unpacked = $destination . '.unpacked';
        $filesystem = new Filesystem();

        try {
            $this->download(
                sprintf(
                    self::MAXMIND_URL,
                    $edition,
                ),
                $archive,
                [
                    $this->maxmindAccountId,
                    $this->maxmindLicenseKey,
                ],
            );

            new PharData($archive)->extractTo(
                $unpacked,
                overwrite: true,
            );

            $databases = glob($unpacked . '/*/*.mmdb');

            if (
                false === $databases
                || [] === $databases
            ) {
                throw new RuntimeException(sprintf(
                    'The %s archive holds no database.',
                    $edition,
                ));
            }

            if (
                !rename(
                    $databases[0],
                    $destination,
                )
            ) {
                throw new RuntimeException(sprintf(
                    'Could not move the unpacked database to "%s".',
                    $destination,
                ));
            }
        } finally {
            $filesystem->remove([
                $archive,
                $unpacked,
            ]);
        }
    }

    /**
     * Streamed to disk: the largest file is some eighty megabytes and the workers run under a memory limit sized for
     * bookkeeping.
     *
     * @param array{0: string, 1: string}|null $auth
     */
    private function download(
        string $url,
        string $destination,
        ?array $auth = null,
    ): void {
        $response = $this->httpClient->request(
            'GET',
            $url,
            null !== $auth ? ['auth_basic' => $auth] : [],
        );

        $handle = fopen(
            $destination,
            'w',
        );

        if (false === $handle) {
            throw new RuntimeException(sprintf(
                'Could not open "%s" for writing.',
                $destination,
            ));
        }

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite(
                    $handle,
                    $chunk->getContent(),
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function prove(string $path): void
    {
        $reader = new Reader($path);

        try {
            if (null === $reader->get(self::PROOF_ADDRESS)) {
                throw new RuntimeException(sprintf(
                    'The download does not answer for %s.',
                    self::PROOF_ADDRESS,
                ));
            }
        } finally {
            $reader->close();
        }
    }
}
