<?php

declare(strict_types=1);

namespace App\Security\User;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function hash_hmac;
use function implode;

/**
 * Reduces a request to the fingerprints {@see \App\Service\User\KnownDeviceRegistry} keys recognition on: what kind
 * of device this is and the networks it could be said to be on, hashed apart because they are learned apart. Browser
 * and OS versions are left out of the device key so a routine update does not read as a new device.
 */
final readonly class DeviceFingerprint
{
    private const string HMAC_ALGO = 'sha256';

    public function __construct(
        private UserAgentParser $userAgentParser,
        private IpNetworkResolver $networkResolver,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * `networks` is empty when the address does not parse: an unnameable network is one recognition cannot vouch
     * for, not one of its own.
     *
     * @return array{device: string, networks: list<string>, browser: ?string, operatingSystem: ?string}
     */
    public function describe(Request $request): array
    {
        $meta = $this->userAgentParser->parseRequest($request);

        return [
            'device' => $this->hash(
                'device',
                [
                    UserAgentParser::family($meta['browser']) ?? '',
                    UserAgentParser::family($meta['operatingSystem']) ?? '',
                    self::languages($request),
                ],
            ),
            'networks' => array_map(
                fn (string $identifier): string => $this->hash(
                    'network',
                    [$identifier],
                ),
                $this->networkResolver->identify($request->getClientIp()),
            ),
            'browser' => $meta['browser'],
            'operatingSystem' => $meta['operatingSystem'],
        ];
    }

    /**
     * Keyed on the application secret and prefixed with what is being hashed, so installations never share a
     * fingerprint and a device fingerprint can never collide with a network one.
     *
     * @param list<string> $parts
     */
    private function hash(
        string $kind,
        array $parts,
    ): string {
        return hash_hmac(
            self::HMAC_ALGO,
            implode(
                "\0",
                [
                    $kind,
                    ...$parts,
                ],
            ),
            $this->secret,
        );
    }

    /**
     * Read through Symfony's parsing so the same preferences spelled differently compare equal; a browser that
     * states none lands on the empty string, a device of its own rather than one matching everybody.
     */
    private static function languages(Request $request): string
    {
        return implode(
            ',',
            $request->getLanguages(),
        );
    }
}
