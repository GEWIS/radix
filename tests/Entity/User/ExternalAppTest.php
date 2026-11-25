<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use App\Entity\User\Enums\JWTClaims;
use App\Entity\User\ExternalApp;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ExternalApp::class)]
#[CoversClass(ExternalAppSignature::class)]
#[CoversClass(ExternalAppTokenDelivery::class)]
final class ExternalAppTest extends TestCase
{
    /**
     * An application that asks for no claims still gets the member number: without it the token names nobody.
     */
    public function testAnApplicationWithoutClaimsOnlyReceivesTheMemberNumber(): void
    {
        $app = new ExternalApp();

        self::assertSame(
            [JWTClaims::Lidnr],
            $app->getClaims(),
        );

        $app->setClaims([JWTClaims::Email, JWTClaims::IsMember]);

        self::assertSame(
            [
                JWTClaims::Email,
                JWTClaims::IsMember,
            ],
            $app->getClaims(),
        );
    }

    /**
     * An application may be switched off, or expire on its own; either makes it unable to mint tokens.
     */
    public function testAnApplicationIsActiveUntilItIsDisabledOrExpires(): void
    {
        $app = new ExternalApp();
        self::assertTrue($app->isActive());

        $app->enabled = false;
        self::assertFalse($app->isActive());

        $app->enabled = true;
        $app->expiresAt = new DateTimeImmutable('+1 day');
        self::assertTrue($app->isActive());

        $app->expiresAt = new DateTimeImmutable('-1 day');
        self::assertFalse($app->isActive());
    }

    /**
     * Only the legacy profile uses a shared secret; the others are verified through the association's keys.
     */
    public function testOnlyTheLegacySignatureUsesASharedSecret(): void
    {
        foreach (ExternalAppSignature::cases() as $signature) {
            self::assertSame(
                ExternalAppSignature::HS512 === $signature,
                $signature->usesSharedSecret(),
                $signature->value,
            );
        }
    }

    public function testTheDeliveryAndSignatureLabelsAreTranslatable(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        self::assertSame(
            'EdDSA (recommended)',
            ExternalAppSignature::EdDSA->trans($translator),
        );
        self::assertSame(
            'URL fragment (#token=)',
            ExternalAppTokenDelivery::Fragment->trans($translator),
        );
    }
}
