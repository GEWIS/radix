<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Entity\User\Session;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function hash_equals;
use function hash_hmac;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SessionRowSignature
{
    private const string ALGO = 'sha256';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function forRow(Session $session): string
    {
        return $this->compute(
            $session,
            $session->getHashedToken(),
            $session->getPreviousHashedToken(),
            $session->getPreviousTokenValidUntil(),
        );
    }

    public function forRotation(
        Session $session,
        string $newHashedToken,
        DateTimeImmutable $validUntil,
    ): string {
        return $this->compute(
            $session,
            $newHashedToken,
            $session->getHashedToken(),
            $validUntil,
        );
    }

    /**
     * The legacy branch keeps rows signed before the firewall and the credentials hash were covered, so deploying
     * this does not sign everybody out. Each is re-signed by the rotation that follows, so it can go once every row
     * has rotated: ninety days, per `config/packages/session.yaml`.
     */
    public function verify(Session $session): bool
    {
        return hash_equals(
            $this->forRow($session),
            $session->getSignature(),
        ) || hash_equals(
            $this->legacyForRow($session),
            $session->getSignature(),
        );
    }

    private function compute(
        Session $session,
        string $hashedToken,
        ?string $previousHashedToken,
        ?DateTimeImmutable $previousTokenValidUntil,
    ): string {
        return hash_hmac(
            self::ALGO,
            json_encode(
                [
                    'series' => $session->getSeries(),
                    'hashedToken' => $hashedToken,
                    'previousHashedToken' => $previousHashedToken,
                    'previousTokenValidUntil' => $previousTokenValidUntil?->getTimestamp(),
                    'userIdentifier' => $session->getUserIdentifier(),
                    'firewallName' => $session->getFirewallName(),
                    'credentials' => $session->getSignaturePropertiesHash(),
                    'expiresAt' => $session->getExpiresAt()->getTimestamp(),
                ],
                JSON_THROW_ON_ERROR,
            ),
            $this->secret,
        );
    }

    private function legacyForRow(Session $session): string
    {
        $fields = [
            $session->getSeries(),
            $session->getHashedToken(),
            $session->getUserIdentifier(),
            $session->getExpiresAt()->getTimestamp(),
        ];

        $previousHashedToken = $session->getPreviousHashedToken();
        $previousTokenValidUntil = $session->getPreviousTokenValidUntil();

        if (
            null !== $previousHashedToken
            && null !== $previousTokenValidUntil
        ) {
            $fields[] = $previousHashedToken;
            $fields[] = $previousTokenValidUntil->getTimestamp();
        }

        return hash_hmac(
            self::ALGO,
            implode(
                ':',
                $fields,
            ),
            $this->secret,
        );
    }
}
