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
        $row->setSignature($signature->forRow($row));

        self::assertTrue($signature->verify($row));
    }

    public function testMovingARowToTheOtherFirewallBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->setSignature($signature->forRow($row));

        $row->setFirewallName('company');

        self::assertFalse($signature->verify($row));
    }

    public function testFreezingTheCredentialsFingerprintBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->setSignature($signature->forRow($row));

        $row->setSignaturePropertiesHash('the fingerprint the account had two passwords ago');

        self::assertFalse($signature->verify($row));
    }

    public function testRepointingARowAtAnotherAccountBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->setSignature($signature->forRow($row));

        $row->setUserIdentifier('8001');

        self::assertFalse($signature->verify($row));
    }

    public function testExtendingARowsExpiryBreaksItsSignature(): void
    {
        $signature = new SessionRowSignature(self::SECRET);
        $row = $this->row();
        $row->setSignature($signature->forRow($row));

        $row->setExpiresAt(new DateTimeImmutable('2027-11-29 12:00:00'));

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

        $row->setPreviousHashedToken($row->getHashedToken());
        $row->setPreviousTokenValidUntil($validUntil);
        $row->setHashedToken('the hashed token it is about to hold');
        $row->setSignature($rotated);

        self::assertTrue($signature->verify($row));
    }

    /**
     * Rows signed before the firewall and the fingerprint were covered still verify, so that deploying this does not
     * sign everybody out at once. Each is re-signed in the new shape by the rotation that follows, which is what lets
     * the second branch go once every row has rotated.
     */
    public function testARowSignedBeforeTheShapeChangedStillVerifies(): void
    {
        $row = $this->row();
        $row->setSignature(hash_hmac(
            'sha256',
            implode(
                ':',
                [
                    $row->getSeries(),
                    $row->getHashedToken(),
                    $row->getUserIdentifier(),
                    $row->getExpiresAt()->getTimestamp(),
                ],
            ),
            self::SECRET,
        ));

        self::assertTrue(new SessionRowSignature(self::SECRET)->verify($row));
    }

    public function testARowSignedBeforeTheShapeChangedStillVerifiesAfterARotation(): void
    {
        $validUntil = new DateTimeImmutable('2026-08-31 12:01:00');
        $row = $this->row();
        $row->setPreviousHashedToken('the hashed token it held before');
        $row->setPreviousTokenValidUntil($validUntil);
        $row->setSignature(hash_hmac(
            'sha256',
            implode(
                ':',
                [
                    $row->getSeries(),
                    $row->getHashedToken(),
                    $row->getUserIdentifier(),
                    $row->getExpiresAt()->getTimestamp(),
                    'the hashed token it held before',
                    $validUntil->getTimestamp(),
                ],
            ),
            self::SECRET,
        ));

        self::assertTrue(new SessionRowSignature(self::SECRET)->verify($row));
    }

    public function testARowDoesNotVerifyAgainstAnotherSecret(): void
    {
        $row = $this->row();
        $row->setSignature(new SessionRowSignature(self::SECRET)->forRow($row));

        self::assertFalse(new SessionRowSignature('somebody else\'s secret')->verify($row));
    }

    private function row(): Session
    {
        $session = new Session();
        $session->setSeries('nu5wKr9Kx1lFhVJPBnIeUJ6NUvJyPXhMkYFXCJt3aVg');
        $session->setHashedToken('the hashed token it is holding');
        $session->setUserIdentifier('8025');
        $session->setFirewallName('main');
        $session->setSignaturePropertiesHash('the fingerprint the account had when it signed in');
        $session->setExpiresAt(new DateTimeImmutable('2026-11-29 12:00:00'));

        return $session;
    }
}
