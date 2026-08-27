<?php

declare(strict_types=1);

namespace App\Security\User;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function bin2hex;
use function hash_hmac;
use function implode;
use function inet_pton;
use function strlen;
use function substr;

/**
 * Reduces a request to the coarse description of a device that {@see \App\Entity\User\KnownDevice} is keyed on: the
 * browser family, the operating system family, the network the request arrived from and the languages it asks for.
 *
 * Versions are left out because Chrome and Firefox move a major version every few weeks, which would announce a new
 * device to everybody each time; the operating system version is only known at all when the browser sent
 * `Sec-CH-UA-Platform-Version`, which Firefox and Safari never do. The address is cut to its network because IPv6
 * privacy addressing rotates the host part daily and mobile networks reassign constantly.
 *
 * What is left is a weak signal: on one campus network a great many people are the same browser on the same system. It
 * decides only whether a sign-in that has already succeeded is written to somebody about, never whether they may sign
 * in.
 */
final readonly class DeviceFingerprint
{
    private const string HMAC_ALGO = 'sha256';

    public function __construct(
        private UserAgentParser $userAgentParser,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * The stored fingerprint, alongside the names it was derived from so a member can be shown a device they
     * recognise.
     *
     * @return array{fingerprint: string, browser: ?string, operatingSystem: ?string}
     */
    public function describe(Request $request): array
    {
        $meta = $this->userAgentParser->parseRequest($request);

        $parts = [
            UserAgentParser::family($meta['browser']) ?? '',
            UserAgentParser::family($meta['operatingSystem']) ?? '',
            self::network($request->getClientIp()),
            self::languages($request),
        ];

        return [
            'fingerprint' => hash_hmac(
                self::HMAC_ALGO,
                implode(
                    "\0",
                    $parts,
                ),
                $this->secret,
            ),
            'browser' => $meta['browser'],
            'operatingSystem' => $meta['operatingSystem'],
        ];
    }

    /**
     * The languages the browser asks for, in the order it asks for them.
     *
     * Read through Symfony's parsing rather than off the header, for the reason the address is packed before it is
     * cut: the same preferences written with different quality values or casing are the same preferences. A browser
     * that states none lands on the empty string, which is a device of its own rather than one matching everybody.
     *
     * It is what tells apart two people who are otherwise the same browser on the same system on one network, which is
     * where the rest of this key is weakest.
     */
    private static function languages(Request $request): string
    {
        return implode(
            ',',
            $request->getLanguages(),
        );
    }

    /**
     * The network an address sits on: the first three octets of an IPv4 address, the first four groups of an IPv6 one.
     *
     * Packed first, so that the many spellings of the same IPv6 address all reduce to the same bytes. An address that
     * does not parse yields an empty network rather than being passed through, which keeps a malformed
     * `X-Forwarded-For` from reading as a device of its own.
     */
    private static function network(?string $address): string
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

        return bin2hex(substr(
            $packed,
            0,
            4 === strlen($packed) ? 3 : 8,
        ));
    }
}
