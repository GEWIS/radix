<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity\Enums;

use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(ExternalSignupVerificationPurpose::class)]
final class ExternalSignupVerificationPurposeTest extends TestCase
{
    /**
     * Confirming a sign-up and managing one later use different links, templates and subject lines; one page for
     * both would let a management token confirm an address.
     */
    public function testTheTwoPurposesHaveTheirOwnLinkTemplateAndSubject(): void
    {
        self::assertSame(
            'activity/external_signup_verify',
            ExternalSignupVerificationPurpose::Verify->routeName(),
        );
        self::assertSame(
            'activity/external_signup_manage',
            ExternalSignupVerificationPurpose::Manage->routeName(),
        );
        self::assertSame(
            'emails/activity/external-signup-verification.html.twig',
            ExternalSignupVerificationPurpose::Verify->emailTemplate(),
        );
        self::assertSame(
            'emails/activity/external-signup-manage.html.twig',
            ExternalSignupVerificationPurpose::Manage->emailTemplate(),
        );

        self::assertSame(
            'Confirm your sign-up for Borrel (Leden)',
            sprintf(
                ExternalSignupVerificationPurpose::Verify->subjectFormat(),
                'Borrel',
                'Leden',
            ),
        );
        self::assertSame(
            'Manage your sign-up for Borrel (Leden)',
            sprintf(
                ExternalSignupVerificationPurpose::Manage->subjectFormat(),
                'Borrel',
                'Leden',
            ),
        );
    }

    /**
     * Every case implements all three exhaustive matches.
     */
    public function testEveryPurposeAnswersEveryMapping(): void
    {
        foreach (ExternalSignupVerificationPurpose::cases() as $purpose) {
            self::assertNotSame(
                '',
                $purpose->routeName(),
                $purpose->value,
            );
            self::assertNotSame(
                '',
                $purpose->emailTemplate(),
                $purpose->value,
            );
            self::assertNotSame(
                '',
                $purpose->subjectFormat(),
                $purpose->value,
            );
        }
    }
}
