<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Message\Database;

use App\Message\Database\RegistrationUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

/**
 * The case owns the template and both subjects, so the service and the handler share no loose string. Only a
 * membership confirmation concerns a member rather than a prospective member.
 */
final class RegistrationUpdateTest extends TestCase
{
    /**
     * @return array<string, array{RegistrationUpdate, string, string, string, bool}>
     */
    public static function updates(): array
    {
        return [
            'registration' => [
                RegistrationUpdate::Registration,
                'database/email/member-registration.html.twig',
                'GEWIS registration',
                'New member registration: ',
                false,
            ],
            'welcome' => [
                RegistrationUpdate::Welcome,
                'database/email/member-welcome.html.twig',
                'Your GEWIS membership has been confirmed',
                'Membership confirmed: ',
                true,
            ],
            'checkout expired' => [
                RegistrationUpdate::CheckoutExpired,
                'database/email/checkout-expired.html.twig',
                'Complete your GEWIS registration',
                'Membership payment expired: ',
                false,
            ],
            'checkout failed' => [
                RegistrationUpdate::CheckoutFailed,
                'database/email/checkout-failed.html.twig',
                'Your GEWIS membership fee payment has failed',
                'Membership payment failed: ',
                false,
            ],
            'refund created' => [
                RegistrationUpdate::RefundCreated,
                'database/email/refund-created.html.twig',
                'Your GEWIS membership fee is being refunded',
                'Membership payment refund started: ',
                false,
            ],
            'payment link resent' => [
                RegistrationUpdate::PaymentLinkResent,
                'database/email/payment-link-resent.html.twig',
                'Complete your GEWIS registration',
                'Membership payment link sent again: ',
                false,
            ],
        ];
    }

    #[DataProvider('updates')]
    public function testEachCaseNamesItsTemplateItsOwnSubjectAndTheSecretarys(
        RegistrationUpdate $update,
        string $template,
        string $subject,
        string $secretaryPrefix,
        bool $isAboutMember,
    ): void {
        self::assertSame(
            $template,
            $update->template(),
        );
        self::assertSame(
            $subject,
            $update->subject(),
        );
        // The secretary sorts by who it is about, so the name ends the subject rather than replacing it.
        self::assertSame(
            $secretaryPrefix . 'Ada Lovelace',
            $update->secretarySubject('Ada Lovelace'),
        );
        self::assertSame(
            $isAboutMember,
            $update->isAboutMember(),
        );
    }

    public function testOnlyTheConfirmationReadsTheMemberRegister(): void
    {
        $aboutMembers = array_values(array_filter(
            RegistrationUpdate::cases(),
            static fn (RegistrationUpdate $update): bool => $update->isAboutMember(),
        ));

        self::assertSame(
            [RegistrationUpdate::Welcome],
            $aboutMembers,
        );
    }
}
