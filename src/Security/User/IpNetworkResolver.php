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
use function is_int;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

/**
 * Reduces an IP address to names for the network it sits on: the autonomous system announcing it (the whole
 * university is one AS, a home ISP another) and the raw address prefix. The lookups are against local databases
 * fetched by {@see \App\Command\User\UpdateIpDatabasesCommand}, so no address leaves this machine; without them only
 * the prefix answers, which is also why the readers are opened per lookup rather than held across requests.
 *
 * The databases are MaxMind's GeoLite editions where a deployment has credentials and IPLocate's free files where it
 * does not, and the two spell their records differently, which is what the field lists below absorb.
 */
final readonly class IpNetworkResolver
{
    public const string ASN_DATABASE = 'ip-to-asn.mmdb';

    /** Display only, never part of recognition: a "network" as wide as a country would vouch for every attacker in it. */
    public const string LOCATION_DATABASE = 'ip-to-location.mmdb';

    private const string MAPPED_V4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    public function __construct(
        #[Autowire('%app.geoip.directory%')]
        private string $directory,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Every name for the network this address sits on, widest first: `as:` and the AS number when the database
     * answers, always the `pfx:` prefix (three octets of IPv4, the first four groups of IPv6), and nothing at all
     * for an address that does not parse. An attacker varies the address, so a malformed one must not read as a
     * network of its own.
     *
     * @return list<string>
     */
    public function identify(?string $address): array
    {
        $packed = self::pack($address);

        if (null === $packed) {
            return [];
        }

        $canonical = inet_ntop($packed);

        if (false === $canonical) {
            return [];
        }

        $identifiers = [];
        $asn = self::field(
            $this->record(
                self::ASN_DATABASE,
                $canonical,
            ),
            'autonomous_system_number',
            'asn',
        );

        if (null !== $asn) {
            $identifiers[] = 'as:' . $asn;
        }

        $identifiers[] = 'pfx:' . bin2hex(substr(
            $packed,
            0,
            4 === strlen($packed) ? 3 : 8,
        ));

        return $identifiers;
    }

    /**
     * The network as somebody would recognise it in a security notice, e.g. "SURF B.V. (AS1161)", or null when the
     * database cannot name it.
     */
    public function networkName(?string $address): ?string
    {
        $packed = self::pack($address);

        if (null === $packed) {
            return null;
        }

        $canonical = inet_ntop($packed);

        if (false === $canonical) {
            return null;
        }

        $record = $this->record(
            self::ASN_DATABASE,
            $canonical,
        );
        $organisation = self::field(
            $record,
            'autonomous_system_organization',
            'org',
            'name',
        );
        $asn = self::field(
            $record,
            'autonomous_system_number',
            'asn',
        );

        if (null === $organisation) {
            return null !== $asn
                ? 'AS' . $asn
                : null;
        }

        return null !== $asn ? sprintf(
            '%s (AS%s)',
            $organisation,
            $asn,
        ) : $organisation;
    }

    /**
     * Where an address is, as somebody would recognise it in a security notice: "Eindhoven, The Netherlands" from a
     * city database, the country alone from a country-only one, null when neither answers.
     */
    public function locationName(?string $address): ?string
    {
        $packed = self::pack($address);

        if (null === $packed) {
            return null;
        }

        $canonical = inet_ntop($packed);

        if (false === $canonical) {
            return null;
        }

        $record = $this->record(
            self::LOCATION_DATABASE,
            $canonical,
        );

        if (!is_array($record)) {
            return null;
        }

        $city = self::field(
            $record['city']['names'] ?? null,
            'en',
        );
        $country = self::field(
            $record,
            'country_name',
        ) ?? self::field(
            $record['country']['names'] ?? null,
            'en',
        );

        if (null === $country) {
            return $city;
        }

        return null !== $city ? sprintf(
            '%s, %s',
            $city,
            $country,
        ) : $country;
    }

    /**
     * IPv4 arrives written as `::ffff:1.2.3.4` from dual-stack listeners; unmapped so it reads as the IPv4 address
     * it is.
     */
    private static function pack(?string $address): ?string
    {
        if (
            null === $address
            || '' === $address
        ) {
            return null;
        }

        $packed = @inet_pton($address);

        if (false === $packed) {
            return null;
        }

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

        return $packed;
    }

    private function record(
        string $database,
        string $address,
    ): mixed {
        $path = $this->directory . '/' . $database;

        if (!file_exists($path)) {
            return null;
        }

        try {
            $reader = new Reader($path);

            try {
                return $reader->get($address);
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
    }

    /**
     * The first of the spellings the record answers to, GeoLite's integers included.
     */
    private static function field(
        mixed $record,
        string ...$fields,
    ): ?string {
        if (!is_array($record)) {
            return null;
        }

        foreach ($fields as $field) {
            $value = $record[$field] ?? null;

            if (is_int($value)) {
                return (string) $value;
            }

            if (
                is_string($value)
                && '' !== $value
            ) {
                return $value;
            }
        }

        return null;
    }
}
