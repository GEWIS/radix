<?php

declare(strict_types=1);

namespace App\Tests\Entity\Application\Enums;

use App\Entity\Application\Enums\NotificationCategory;
use App\Entity\Application\Enums\NotificationType;
use App\Security\User\Firewall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * A notification's appearance lives in the enum: its icon, topic, summary line and link. A wrong entry only shows in
 * the notification centre, so every case is checked here.
 */
#[CoversClass(NotificationType::class)]
final class NotificationTypePresentationTest extends TestCase
{
    /**
     * The account events are the ones about the member's own sign-in, which are shown one at a time rather than rolled
     * into a summary.
     */
    private const array SECURITY = [
        NotificationType::SignIn,
        NotificationType::PasswordChanged,
        NotificationType::MfaEnabled,
        NotificationType::MfaDisabled,
        NotificationType::BackupCodesRegenerated,
    ];

    private const array PROPOSAL_STATES = [
        NotificationType::ActivityProposalScheduled,
        NotificationType::ActivityProposalDeclined,
        NotificationType::ActivityProposalBudgetDue,
        NotificationType::ActivityProposalLapsed,
    ];

    public function testEveryKindHasAnIconCategoryAndLinkLabel(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertNotSame(
                '',
                $type->icon(),
                $type->value,
            );
            self::assertNotSame(
                '',
                $type->linkLabel()->getMessage(),
                $type->value,
            );
            self::assertNotSame(
                '',
                $type->route(),
                $type->value,
            );
            self::assertNotSame(
                '',
                $type->manyRoute(),
                $type->value,
            );
        }
    }

    /**
     * Icons are there to tell kinds apart at a glance; two unrelated things sharing one is the bug this catches.
     */
    public function testKindsThatAreDifferentThingsDoNotShareAnIcon(): void
    {
        self::assertSame(
            'fa-images',
            NotificationType::AlbumPublished->icon(),
        );
        self::assertSame(
            'fa-calendar-day',
            NotificationType::ActivityPublished->icon(),
        );
        self::assertSame(
            'fa-right-to-bracket',
            NotificationType::SignIn->icon(),
        );
        self::assertSame(
            'fa-key',
            NotificationType::PasswordChanged->icon(),
        );
        self::assertSame(
            'fa-lock',
            NotificationType::MfaEnabled->icon(),
        );
        self::assertSame(
            'fa-unlock',
            NotificationType::MfaDisabled->icon(),
        );
        self::assertNotSame(
            NotificationType::SignupClosing->icon(),
            NotificationType::ActivityProposalScheduled->icon(),
        );
    }

    /**
     * Only the account events cannot be summarised, because a count hides the sign-in the reader wants to see.
     */
    public function testOnlyAccountEventsAreKeptApartAcrossSubjects(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertSame(
                !in_array(
                    $type,
                    self::SECURITY,
                    true,
                ),
                $type->groupsAcrossSubjects(),
                $type->value,
            );
        }
    }

    public function testEveryKindBelongsToTheTopicTheSettingsPageGroupsItUnder(): void
    {
        self::assertSame(
            NotificationCategory::Albums,
            NotificationType::AlbumPublished->category(),
        );
        self::assertSame(
            NotificationCategory::Activities,
            NotificationType::ActivityPublished->category(),
        );
        self::assertSame(
            NotificationCategory::SignIns,
            NotificationType::SignIn->category(),
        );
        self::assertSame(
            NotificationCategory::AccountSecurity,
            NotificationType::BackupCodesRegenerated->category(),
        );
        self::assertSame(
            NotificationCategory::DataExports,
            NotificationType::DataExportReady->category(),
        );
        self::assertSame(
            NotificationCategory::ActivityReviews,
            NotificationType::ActivityAwaitingReview->category(),
        );
        self::assertSame(
            NotificationCategory::CareerReviews,
            NotificationType::CompanyBannerAwaitingReview->category(),
        );
        self::assertSame(
            NotificationCategory::BodyReviews,
            NotificationType::OrganInformationRevisionAwaitingReview->category(),
        );
        self::assertSame(
            NotificationCategory::PollReviews,
            NotificationType::PollRevisionAwaitingReview->category(),
        );
        self::assertSame(
            NotificationCategory::SignupReminders,
            NotificationType::SignupClosing->category(),
        );
        self::assertSame(
            NotificationCategory::OptionCalendar,
            NotificationType::ActivityProposalLapsed->category(),
        );
    }

    /**
     * A summary line always has a count and the count is what makes it a summary.
     */
    public function testASummaryLineCarriesTheCount(): void
    {
        foreach (NotificationType::cases() as $type) {
            $message = $type->manyMessage(3);

            self::assertStringContainsString(
                '%count%',
                $message->getMessage(),
                $type->value,
            );
            self::assertSame(
                ['%count%' => 3],
                $message->getParameters(),
                $type->value,
            );
        }
    }

    /**
     * A kind about a page that needs no parameters says so, while one about a subject passes that subject through.
     */
    public function testRouteParametersOnlyPassWhatTheTargetPageNeeds(): void
    {
        self::assertSame(
            [
                'type' => 'album',
                'album' => 12,
            ],
            NotificationType::AlbumPublished->routeParameters(12),
        );
        self::assertSame(
            ['activity' => 12],
            NotificationType::ActivityPublished->routeParameters(12),
        );

        foreach (
            [
                NotificationType::ActivityAwaitingReview,
                NotificationType::CompanyRevisionAwaitingReview,
                NotificationType::VacancyRevisionAwaitingReview,
                NotificationType::OrganInformationRevisionAwaitingReview,
                NotificationType::PollRevisionAwaitingReview,
            ] as $type
        ) {
            self::assertSame(
                ['revision' => 12],
                $type->routeParameters(12),
                $type->value,
            );
        }

        foreach (
            [
                NotificationType::CompanyBannerAwaitingReview,
                NotificationType::SignIn,
                NotificationType::PasswordChanged,
                NotificationType::MfaEnabled,
                NotificationType::MfaDisabled,
                NotificationType::BackupCodesRegenerated,
                NotificationType::DataExportReady,
                NotificationType::ActivityProposalAwaitingDecision,
            ] as $type
        ) {
            self::assertSame(
                [],
                $type->routeParameters(12),
                $type->value,
            );
            self::assertSame(
                [],
                $type->routeParameters(null),
                $type->value,
            );
        }
    }

    /**
     * A reminder and a proposal concern something that recurs, so that thing lives in the notification's context
     * rather than in its subject, which has a unique index over the kind.
     */
    public function testRecurringKindsTakeTheirSubjectFromTheContext(): void
    {
        foreach (
            [
                NotificationType::SignupClosing,
                NotificationType::SignupClosingWithFields,
            ] as $type
        ) {
            self::assertSame(
                ['activity' => '9'],
                $type->routeParameters(
                    12,
                    ['activity' => '9'],
                ),
                $type->value,
            );
            self::assertSame(
                [],
                $type->routeParameters(12),
                $type->value,
            );
        }

        foreach (self::PROPOSAL_STATES as $type) {
            self::assertSame(
                ['proposal' => '9'],
                $type->routeParameters(
                    12,
                    ['proposal' => '9'],
                ),
                $type->value,
            );
            self::assertSame(
                [],
                $type->routeParameters(12),
                $type->value,
            );
        }
    }

    /**
     * A single notification points at one thing and a run of them at the list they belong to; an account notice points
     * at the security page of the firewall the reader is on.
     */
    public function testASingleNotificationAndARunPointAtDifferentPages(): void
    {
        self::assertSame(
            'photo/album',
            NotificationType::AlbumPublished->route(),
        );
        self::assertSame(
            'photo/index',
            NotificationType::AlbumPublished->manyRoute(),
        );
        self::assertSame(
            'activity/view',
            NotificationType::ActivityPublished->route(),
        );
        self::assertSame(
            'activity/index',
            NotificationType::ActivityPublished->manyRoute(),
        );

        foreach (self::SECURITY as $type) {
            self::assertSame(
                'user_security_index',
                $type->route(Firewall::Main),
                $type->value,
            );
            self::assertSame(
                'company_user_security_index',
                $type->route(Firewall::Company),
                $type->value,
            );
            self::assertSame(
                'company_user_security_index',
                $type->manyRoute(Firewall::Company),
                $type->value,
            );
        }

        foreach (NotificationType::cases() as $type) {
            foreach ([Firewall::Main, Firewall::Company] as $firewall) {
                self::assertNotSame(
                    '',
                    $type->route($firewall),
                    $type->value,
                );
                self::assertNotSame(
                    '',
                    $type->manyRoute($firewall),
                    $type->value,
                );
            }
        }
    }

    /**
     * A security notice is emailed from its own handler and opens with a sentence naming the account; a notice that
     * goes out in a digest has no such opening.
     */
    public function testOnlySecurityNoticesHaveAnEmailSummary(): void
    {
        foreach (NotificationType::cases() as $type) {
            $summary = $type->emailSummary('lid@example.org');

            if (
                in_array(
                    $type,
                    self::SECURITY,
                    true,
                )
            ) {
                self::assertNotNull(
                    $summary,
                    $type->value,
                );
                self::assertStringContainsString(
                    'lid@example.org',
                    $summary,
                    $type->value,
                );

                continue;
            }

            self::assertNull(
                $summary,
                $type->value,
            );
        }
    }
}
