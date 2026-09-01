<?php

declare(strict_types=1);

namespace App\Security\User;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

use function hash_hmac;
use function implode;

/**
 * Reduces a request to the two coarse descriptions {@see \App\Service\User\KnownDeviceRegistry} keys recognition on:
 * what kind of device this is (the browser family, the operating system family and the languages it asks for) and
 * where it is ({@see IpNetworkResolver}). The two are hashed apart because they are learned apart: a device first
 * seen at home must still be that device on campus, and a network, once seen, covers every device the account is
 * known on.
 *
 * Versions are left out because Chrome and Firefox move a major version every few weeks, which would announce a new
 * device to everybody each time; the operating system version is only known at all when the browser sent
 * `Sec-CH-UA-Platform-Version`, which Firefox and Safari never do.
 *
 * What is left is a weak signal: on one campus network a great many people are the same browser on the same system.
 * It decides only whether a sign-in that has already succeeded is written to somebody about, never whether they may
 * sign in.
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
     * The stored fingerprints, alongside the names the device one was derived from so a member can be shown a device
     * they recognise. The network is null when the address does not parse: an unnameable network is one recognition
     * cannot vouch for, not one of its own.
     *
     * @return array{device: string, network: ?string, browser: ?string, operatingSystem: ?string}
     */
    public function describe(Request $request): array
    {
        $meta = $this->userAgentParser->parseRequest($request);
        $network = $this->networkResolver->identify($request->getClientIp());

        return [
            'device' => $this->hash(
                'device',
                [
                    UserAgentParser::family($meta['browser']) ?? '',
                    UserAgentParser::family($meta['operatingSystem']) ?? '',
                    self::languages($request),
                ],
            ),
            'network' => '' === $network ? null : $this->hash(
                'network',
                [$network],
            ),
            'browser' => $meta['browser'],
            'operatingSystem' => $meta['operatingSystem'],
        ];
    }

    /**
     * Keyed on the application secret so the same device on two installations does not share a fingerprint, and
     * prefixed with what is being hashed so a device fingerprint can never collide with a network one.
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
     * The languages the browser asks for, in the order it asks for them.
     *
     * Read through Symfony's parsing rather than off the header, so that the same preferences written with different
     * quality values or casing are the same preferences. A browser that states none lands on the empty string, which
     * is a device of its own rather than one matching everybody.
     *
     * It is what tells apart two people who are otherwise the same browser on the same system, which is where the
     * rest of this key is weakest.
     */
    private static function languages(Request $request): string
    {
        return implode(
            ',',
            $request->getLanguages(),
        );
    }
}
