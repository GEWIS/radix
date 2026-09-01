<?php

declare(strict_types=1);

namespace App\Security\User;

use MaxMind\Db\Reader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function bin2hex;
use function file_exists;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_string;
use function strlen;
use function substr;

/**
 * Reduces an IP address to the widest name for where it is that still tells one place from another: the autonomous
 * system announcing it, failing that the country, failing that the address prefix.
 *
 * The prefix alone is what {@see \App\Entity\User\KnownNetwork} would be keyed on in effect, and it is far too narrow
 * for how our members live: the university announces whole /16s that eduroam scatters people across per building, and
 * carriers hand phones a different range daily. The AS collapses each of those to a single name (all of the TU/e is
 * one AS, a home subscription's ISP another, a carrier a third), so an account settles on a handful of networks
 * within days and keeps them for years.
 *
 * The lookups are against IPLocate's freely licensed databases on the local disk (see
 * {@see \App\Command\User\UpdateIpDatabasesCommand}), so no address ever leaves this machine to be resolved. Read
 * through `MaxMind\Db\Reader`; the databases are MaxMind-format but not MaxMind's own, which is why the typed `GeoIp2`
 * wrapper with its database-type checks is of no use here. Opened per lookup rather than held: recognition runs once
 * per sign-in and once per three minutes of activity, opening parses only the metadata, and holding a reader would be
 * one more piece of state to carry across requests in worker mode, and one that would have to notice the update
 * command renaming a fresh file over the path.
 *
 * A missing or unreadable database quietly falls through to the next layer rather than failing: this feeds a
 * fingerprint that only decides whether a sign-in is written to somebody about, and the databases are absent
 * everywhere the update command has not run, which is every development machine.
 */
final readonly class IpNetworkResolver
{
    public const string ASN_DATABASE = 'ip-to-asn.mmdb';
    public const string COUNTRY_DATABASE = 'ip-to-country.mmdb';

    /** What an IPv4 address looks like once it has been written as an IPv6 one: ten zero bytes and then `ffff`. */
    private const string MAPPED_V4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    public function __construct(
        #[Autowire('%app.geoip.directory%')]
        private string $directory,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The network identifier for an address: `as:` and the AS number, `cc:` and the country, `pfx:` and the packed
     * address prefix (three octets of an IPv4 address, the first four groups of an IPv6 one), or the empty string for
     * an address that does not parse.
     *
     * Packed before anything else, so that the many spellings of the same address all reduce to the same answer, and
     * so a malformed `X-Forwarded-For` cannot read as a network of its own: it is what an attacker would vary to look
     * like somebody else's network.
     */
    public function identify(?string $address): string
    {
        if (
            null === $address
            || '' === $address
        ) {
            return '';
        }

        $packed = @inet_pton($address);

        if (false === $packed) {
            return '';
        }

        // A dual-stack listener, or a proxy that forwards what it was given, hands us IPv4 written as
        // `::ffff:1.2.3.4`. Unmapped so the databases and the prefix both see the IPv4 address it is.
        if (
            self::MAPPED_V4_PREFIX === substr(
                $packed,
                0,
                12,
            )
        ) {
            $packed = substr(
                $packed,
                12,
            );
        }

        $canonical = inet_ntop($packed);

        if (false === $canonical) {
            return '';
        }

        $asn = $this->lookup(
            self::ASN_DATABASE,
            $canonical,
            'asn',
        );

        if (null !== $asn) {
            return 'as:' . $asn;
        }

        $country = $this->lookup(
            self::COUNTRY_DATABASE,
            $canonical,
            'country_code',
        );

        if (null !== $country) {
            return 'cc:' . $country;
        }

        return 'pfx:' . bin2hex(substr(
            $packed,
            0,
            4 === strlen($packed) ? 3 : 8,
        ));
    }

    private function lookup(
        string $database,
        string $address,
        string $field,
    ): ?string {
        $path = $this->directory . '/' . $database;

        if (!file_exists($path)) {
            return null;
        }

        try {
            $reader = new Reader($path);

            try {
                $record = $reader->get($address);
            } finally {
                $reader->close();
            }
        } catch (Throwable $e) {
            $this->logger?->warning(
                'An IP database could not answer.',
                [
                    'database' => $database,
                    'exception' => $e,
                ],
            );

            return null;
        }

        if (
            !is_array($record)
            || !isset($record[$field])
            || !is_string($record[$field])
            || '' === $record[$field]
        ) {
            return null;
        }

        return $record[$field];
    }
}
