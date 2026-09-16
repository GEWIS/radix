<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\User\Session;
use App\Security\User\SessionRowSignature;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function hash_hmac;
use function implode;

final class SessionRowSignatureTest extends TestCase
{
    private const string SECRET = 'a secret that is only ever this test\'s';

    public function testARowSignsAndVerifies(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->signature = $signature->forRow($row);

        self::assertTrue($signature->verify($row));
    }

    public function testMovingARowToTheOtherFirewallBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->signature = $signature->forRow($row);

        $row->firewallName = 'company';

        self::assertFalse($signature->verify($row));
    }

    public function testFreezingTheCredentialsFingerprintBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->signature = $signature->forRow($row);

        $row->signaturePropertiesHash = 'the fingerprint the account had two passwords ago';

        self::assertFalse($signature->verify($row));
    }

    public function testRepointingARowAtAnotherAccountBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->signature = $signature->forRow($row);

        $row->userIdentifier = '8001';

        self::assertFalse($signature->verify($row));
    }

    public function testExtendingARowsExpiryBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->signature = $signature->forRow($row);

        $row->expiresAt = new DateTimeImmutable('2027-11-29 12:00:00');

        self::assertFalse($signature->verify($row));
    }

    public function testTheSignatureForARotationIsTheRowAsItWillRead(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $validUntil = new DateTimeImmutable('2026-08-31 12:01:00');

        $rotated = $signature->forRotation(
            $row,
            'the hashed token it is about to hold',
            $validUntil,
        );

        $row->previousHashedToken = $row->hashedToken;
        $row->previousTokenValidUntil = $validUntil;
        $row->hashedToken = 'the hashed token it is about to hold';
        $row->signature = $rotated;

        self::assertTrue($signature->verify($row));
    }

    /**
     * Rows signed before the firewall and the fingerprint were covered still verify, so that deploying this does not
     * sign everybody out at once. Each is re-signed in the new shape by the rotation that follows, which is what lets
     * the second branch be removed once every row has rotated.
     */
    public function testARowSignedBeforeTheShapeChangedStillVerifies(): void
    {
        $row = $this->row();
        $row->signature = hash_hmac(
            'sha256',
            implode(
                ':',
                [
                    $row->series,
                    $row->hashedToken,
                    $row->userIdentifier,
                    $row->expiresAt->getTimestamp(),
                ],
            ),
            self::SECRET,
        );

        self::assertTrue(new SessionRowSignature(self::SECRET)->verify($row));
    }

    public function testARowSignedBeforeTheShapeChangedStillVerifiesAfterARotation(): void
    {
        $validUntil = new DateTimeImmutable('2026-08-31 12:01:00');
        $row = $this->row();
        $row->previousHashedToken = 'the hashed token it held before';
        $row->previousTokenValidUntil = $validUntil;
        $row->signature = hash_hmac(
            'sha256',
            implode(
                ':',
                [
                    $row->series,
                    $row->hashedToken,
                    $row->userIdentifier,
                    $row->expiresAt->getTimestamp(),
                    'the hashed token it held before',
                    $validUntil->getTimestamp(),
                ],
            ),
            self::SECRET,
        );

        self::assertTrue(new SessionRowSignature(self::SECRET)->verify($row));
    }

    public function testARowDoesNotVerifyAgainstAnotherSecret(): void
    {
        $row = $this->row();
        $row->signature = new SessionRowSignature(self::SECRET)->forRow($row);

        self::assertFalse(new SessionRowSignature('somebody else\'s secret')->verify($row));
    }

    private function row(): Session
    {
        $session = new Session();
        $session->series = 'nu5wKr9Kx1lFhVJPBnIeUJ6NUvJyPXhMkYFXCJt3aVg';
        $session->hashedToken = 'the hashed token it is holding';
        $session->userIdentifier = '8025';
        $session->firewallName = 'main';
        $session->signaturePropertiesHash = 'the fingerprint the account had when it signed in';
        $session->expiresAt = new DateTimeImmutable('2026-11-29 12:00:00');

        return $session;
    }
}
