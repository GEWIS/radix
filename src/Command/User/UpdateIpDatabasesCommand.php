<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Command\HoldsRunLockTrait;
use App\Security\User\IpNetworkResolver;
use MaxMind\Db\Reader;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function is_dir;
use function mkdir;
use function rename;
use function sprintf;
use function unlink;

/**
 * Fetches fresh copies of the IP databases {@see IpNetworkResolver} answers from: IPLocate's freely licensed files
 * (CC BY-SA 4.0, attribution in the privacy statement), rebuilt daily and published through Git LFS. Weekly is fresh
 * enough; which AS announces an address changes on the timescale of contracts, and a stale answer costs one notice
 * somebody did not need.
 */
#[AsCommand(
    name: 'app:user:update-ip-databases',
    description: 'Fetch fresh copies of the IP databases device recognition and security notices read.',
)]
#[AsCronTask(
    expression: '20 4 * * 1',
    jitter: 900,
    transports: 'maintenance',
)]
final class UpdateIpDatabasesCommand extends Command
{
    use HoldsRunLockTrait;

    private const array DATABASES = [
        IpNetworkResolver::ASN_DATABASE => 'https://media.githubusercontent.com/media/iplocate/ip-address-databases/'
            . 'main/ip-to-asn/ip-to-asn.mmdb',
        IpNetworkResolver::COUNTRY_DATABASE => 'https://media.githubusercontent.com/media/iplocate/'
            . 'ip-address-databases/main/ip-to-country/ip-to-country.mmdb',
    ];

    private const string PROOF_ADDRESS = '131.155.0.1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.geoip.directory%')]
        private readonly string $directory,
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

        $failures = 0;

        foreach (self::DATABASES as $database => $url) {
            $target = $this->directory . '/' . $database;
            $incoming = $target . '.download';

            try {
                $this->download(
                    $url,
                    $incoming,
                );
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
                    'Updated %s.',
                    $database,
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
     * Streamed to disk: the file is some eighty megabytes and the workers run under a memory limit sized for
     * bookkeeping.
     */
    private function download(
        string $url,
        string $destination,
    ): void {
        $response = $this->httpClient->request(
            'GET',
            $url,
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
