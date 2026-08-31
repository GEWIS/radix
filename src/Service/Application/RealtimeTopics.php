<?php

declare(strict_types=1);

namespace App\Service\Application;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function hash_hmac;
use function sprintf;
use function substr;

/**
 * Shared by {@see RealtimeAuthorization} and {@see RealtimeNotifier}: a topic minted for the cookie that does not
 * match the one published to is a message nobody receives, and neither side fails when they drift apart.
 */
final class RealtimeTopics
{
    public const string PUBLIC = 'gewis/public';
    public const string MEMBERS = 'gewis/members';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function user(
        string $firewallName,
        string $userIdentifier,
    ): string {
        return sprintf(
            'gewis/user/%s/%s',
            $firewallName,
            $userIdentifier,
        );
    }

    /**
     * Derived from the series rather than being it. Topics are rendered into the page and travel in the subscribe
     * request's query string, and the series is what {@see \App\Security\User\PersistentSignatureRememberMeHandler}
     * recognises a session by.
     */
    public function session(
        string $firewallName,
        string $series,
    ): string {
        return sprintf(
            'gewis/session/%s/%s',
            $firewallName,
            substr(
                hash_hmac(
                    'sha256',
                    $series,
                    $this->secret,
                ),
                0,
                32,
            ),
        );
    }
}
