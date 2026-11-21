<?php

declare(strict_types=1);

namespace App\Tests\Entity\User\Enums;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\JWTClaims;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

#[CoversClass(JWTClaims::class)]
final class JWTClaimsTest extends TestCase
{
    /**
     * Every claim derives its value from the member; a claim that asks a question returns the answer rather than a
     * field.
     */
    #[DataProvider('claims')]
    public function testEachClaimTakesItsValueFromTheMember(
        JWTClaims $claim,
        bool|int|string|null $expected,
    ): void {
        $member = $this->member();

        self::assertSame(
            $expected,
            $claim->getValue($member),
            $claim->value,
        );
    }

    /**
     * @return iterable<string, array{JWTClaims, bool|int|string|null}>
     */
    public static function claims(): iterable
    {
        yield 'email' => [
            JWTClaims::Email,
            'lid@example.org',
        ];

        yield 'email verified because there is an address' => [
            JWTClaims::EmailVerified,
            true,
        ];

        yield 'family name' => [
            JWTClaims::FamilyName,
            'Lid',
        ];

        yield 'given name' => [
            JWTClaims::GivenName,
            'Test',
        ];

        yield '18 plus' => [
            JWTClaims::Is18Plus,
            true,
        ];

        yield 'is a member' => [
            JWTClaims::IsMember,
            true,
        ];

        yield 'member number' => [
            JWTClaims::Lidnr,
            8100,
        ];

        yield 'membership type' => [
            JWTClaims::MembershipType,
            'ordinary',
        ];

        yield 'middle name' => [
            JWTClaims::MiddleName,
            'van',
        ];

        yield 'full name' => [
            JWTClaims::Name,
            'Test van Lid',
        ];
    }

    /**
     * A member who is not 18 yet, whose membership has expired, or who only has graduate status must not be presented
     * to an application as a member.
     */
    public function testMembershipAndAgeAreDecisionsRatherThanFields(): void
    {
        $member = $this->member();
        $member->type = MembershipTypes::Graduate;
        self::assertFalse(JWTClaims::IsMember->getValue($member));

        $member->type = MembershipTypes::Ordinary;
        $member->expiration = new DateTimeImmutable('-1 day');
        self::assertFalse(JWTClaims::IsMember->getValue($member));

        $member->expiration = new DateTimeImmutable('+1 month');
        $member->birth = new DateTimeImmutable('-17 years');
        self::assertFalse(JWTClaims::Is18Plus->getValue($member));

        $member->birth = new DateTimeImmutable('-18 years');
        self::assertTrue(JWTClaims::Is18Plus->getValue($member));
    }

    /**
     * An address is verified when there is one; an empty column is not a verified address.
     */
    public function testAnEmailIsVerifiedOnlyWhenItIsPresent(): void
    {
        $member = $this->member();
        $member->email = null;

        self::assertNull(JWTClaims::Email->getValue($member));
        self::assertFalse(JWTClaims::EmailVerified->getValue($member));
    }

    public function testEachClaimHasItsOwnLabel(): void
    {
        $labels = [];

        foreach (JWTClaims::cases() as $claim) {
            $labels[$claim->trans($this->translator())] = true;
        }

        self::assertCount(
            count(JWTClaims::cases()),
            $labels,
        );
        self::assertArrayHasKey(
            'Email address',
            $labels,
        );
        self::assertArrayHasKey(
            'Is a member?',
            $labels,
        );
        self::assertArrayHasKey(
            'Member number',
            $labels,
        );
    }

    private function member(): Member
    {
        $member = new Member();
        $member->lidnr = 8100;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = 'van';
        $member->lastName = 'Lid';
        $member->email = 'lid@example.org';
        $member->birth = new DateTimeImmutable('2000-01-01');
        $member->expiration = new DateTimeImmutable('+1 month');
        $member->type = MembershipTypes::Ordinary;

        return $member;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
