<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Message;

use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use App\Entity\Application\Enums\ImageProfile;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\Enums\UserTypes;
use App\Message\Activity\ExternalSignupResendVerificationEmail;
use App\Message\Activity\ExternalSignupTokenEmail;
use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Message\Activity\RenderActivityShareImageMessage;
use App\Message\Application\GenerateImageVariantMessage;
use App\Message\Application\PregenerateImageVariantMessage;
use App\Message\Application\PublishDomainNotificationMessage;
use App\Message\Application\SendNotificationDigestMessage;
use App\Message\Career\CareerReviewDecisionEmail;
use App\Message\Database\RefundProblemEmail;
use App\Message\Database\RegistrationUpdate;
use App\Message\Database\RegistrationUpdateEmail;
use App\Message\Decision\AuthorizationCreatedEmail;
use App\Message\Decision\AuthorizationRevokedEmail;
use App\Message\Education\BuildWatermarkedDocumentMessage;
use App\Message\Education\FlattenCourseDocumentMessage;
use App\Message\Frontpage\ProcessPageImageMessage;
use App\Message\Photo\GenerateAlbumCoverMessage;
use App\Message\Photo\ProcessImageVariantsMessage;
use App\Message\User\CompanyUserInviteEmail;
use App\Message\User\ExportUserDataMessage;
use App\Message\User\PasswordResetRequestEmail;
use App\Message\User\RevokeSessionsRealtimeMessage;
use App\Message\User\SecurityNotificationMessage;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The queued payload is the contract between a request and a worker: everything the handler needs must be in the
 * message. These assertions pin those fields.
 */
final class MessageTest extends TestCase
{
    public function testTheExternalSignupVerificationRequestCarriesTheListAndAddress(): void
    {
        $message = new ExternalSignupResendVerificationEmail(
            42,
            'ada@example.org',
        );

        self::assertSame(
            42,
            $message->getSignupListId(),
        );
        self::assertSame(
            'ada@example.org',
            $message->getEmail(),
        );
    }

    public function testTheExternalSignupTokenCarriesThePlaintextTokenAndItsPurpose(): void
    {
        $message = new ExternalSignupTokenEmail(
            7,
            'selector.verifier',
            ExternalSignupVerificationPurpose::Manage,
        );

        self::assertSame(
            7,
            $message->getExternalSignupId(),
        );
        self::assertSame(
            'selector.verifier',
            $message->getToken(),
        );
        self::assertSame(
            ExternalSignupVerificationPurpose::Manage,
            $message->getPurpose(),
        );
    }

    public function testTheAnnouncementCarriesTheTextAndTheSnapshottedRecipients(): void
    {
        $message = new OrganiserAnnouncementEmail(
            'Subject',
            'Body',
            'Gala',
            'organ@example.org',
            [
                [
                    'email' => 'ada@example.org',
                    'name' => 'Ada',
                    'replacements' => ['COMPANY_NAME' => 'ACME'],
                ],
            ],
        );

        self::assertSame(
            'Subject',
            $message->getSubject(),
        );
        self::assertSame(
            'Body',
            $message->getBody(),
        );
        self::assertSame(
            'Gala',
            $message->getActivityName(),
        );
        self::assertSame(
            'organ@example.org',
            $message->getReplyTo(),
        );
        self::assertSame(
            [
                [
                    'email' => 'ada@example.org',
                    'name' => 'Ada',
                    'replacements' => ['COMPANY_NAME' => 'ACME'],
                ],
            ],
            $message->getRecipients(),
        );
    }

    public function testTheActivityShareImageNamesTheRevisionRatherThanTheActivity(): void
    {
        self::assertSame(
            31,
            new RenderActivityShareImageMessage(31)->getRevisionId(),
        );
    }

    public function testAnImageVariantRequestCarriesItsSourceAndVariant(): void
    {
        $message = new GenerateImageVariantMessage(
            'photos/albums/a.jpg',
            ImageVariant::W320,
        );

        self::assertSame(
            'photos/albums/a.jpg',
            $message->getSourcePath(),
        );
        self::assertSame(
            ImageVariant::W320,
            $message->getVariant(),
        );
    }

    public function testABackfillIsNotForcedUnlessAskedToBe(): void
    {
        $message = new PregenerateImageVariantMessage(
            'photos/albums/a.jpg',
            ImageVariant::W1280,
        );

        self::assertSame(
            'photos/albums/a.jpg',
            $message->getSourcePath(),
        );
        self::assertSame(
            ImageVariant::W1280,
            $message->getVariant(),
        );
        self::assertFalse($message->isForced());

        self::assertTrue(new PregenerateImageVariantMessage(
            'photos/albums/a.jpg',
            ImageVariant::W1280,
            true,
        )->isForced());
    }

    public function testADomainNotificationCarriesItsTypeSubjectAndOptionalRole(): void
    {
        $message = new PublishDomainNotificationMessage(
            NotificationType::AlbumPublished,
            11,
        );

        self::assertSame(
            NotificationType::AlbumPublished,
            $message->getType(),
        );
        self::assertSame(
            11,
            $message->getSubjectId(),
        );
        self::assertNull($message->getRecipientRole());

        $message = new PublishDomainNotificationMessage(
            NotificationType::CompanyBannerAwaitingReview,
            12,
            UserRoles::CompanyAdmin,
        );

        self::assertSame(
            UserRoles::CompanyAdmin,
            $message->getRecipientRole(),
        );
    }

    public function testADigestKeepsItsRecipientAndEntries(): void
    {
        $entries = [
            [
                'text' => 'Your photo was approved',
                'linkLabel' => 'View photo',
                'type' => NotificationType::AlbumPublished,
                'subjectId' => 9,
            ],
        ];
        $message = new SendNotificationDigestMessage(
            'ada@example.com',
            'Ada Lovelace',
            $entries,
        );

        self::assertSame(
            'ada@example.com',
            $message->getEmail(),
        );
        self::assertSame(
            'Ada Lovelace',
            $message->getName(),
        );
        self::assertSame(
            $entries,
            $message->getEntries(),
        );
    }

    public function testAReviewDecisionNamesTheCompanyTheOutcomeAndTheSubject(): void
    {
        $message = new CareerReviewDecisionEmail(
            5,
            RevisionStatus::ChangesRequested,
            'Vacancy X',
            true,
        );

        self::assertSame(
            5,
            $message->getCompanyId(),
        );
        self::assertSame(
            RevisionStatus::ChangesRequested,
            $message->getOutcome(),
        );
        self::assertSame(
            'Vacancy X',
            $message->getSubjectName(),
        );
        self::assertTrue($message->isVacancy());
    }

    public function testARefundProblemCarriesWhatStripeReported(): void
    {
        $message = new RefundProblemEmail(
            're_123',
            'failed',
        );

        self::assertSame(
            're_123',
            $message->getRefundId(),
        );
        self::assertSame(
            'failed',
            $message->getRefundStatus(),
        );
    }

    public function testARegistrationUpdateNamesTheCaseAndTheNumber(): void
    {
        $message = new RegistrationUpdateEmail(
            RegistrationUpdate::CheckoutFailed,
            8025,
        );

        self::assertSame(
            RegistrationUpdate::CheckoutFailed,
            $message->getType(),
        );
        self::assertSame(
            8025,
            $message->getLidnr(),
        );
    }

    public function testTheAuthorizationMailsNameTheirAuthorization(): void
    {
        self::assertSame(
            7,
            new AuthorizationCreatedEmail(7)->authorizationId,
        );
        self::assertSame(
            8,
            new AuthorizationRevokedEmail(8)->authorizationId,
        );
    }

    public function testTheEducationMessagesNameTheirRecord(): void
    {
        self::assertSame(
            9,
            new BuildWatermarkedDocumentMessage(9)->getDownloadId(),
        );
        self::assertSame(
            10,
            new FlattenCourseDocumentMessage(10)->getDocumentId(),
        );
    }

    public function testThePageImageMessageCarriesTheScopeItReportsOn(): void
    {
        $message = new ProcessPageImageMessage(
            'pages/images/1/a.jpg',
            '1',
        );

        self::assertSame(
            'pages/images/1/a.jpg',
            $message->getSourcePath(),
        );
        self::assertSame(
            '1',
            $message->getScope(),
        );
    }

    public function testTheAlbumCoverNamesTheAlbumAndTheVariantsNameTheirProfile(): void
    {
        self::assertSame(
            13,
            new GenerateAlbumCoverMessage(13)->getAlbumId(),
        );

        $message = new ProcessImageVariantsMessage(
            'photos/albums/a.jpg',
            ImageProfile::AlbumCover,
        );

        self::assertSame(
            'photos/albums/a.jpg',
            $message->getSourcePath(),
        );
        self::assertSame(
            ImageProfile::AlbumCover,
            $message->getProfile(),
        );
    }

    public function testAnInviteAndAnExportNameTheirRecord(): void
    {
        $invite = new CompanyUserInviteEmail(
            14,
            'selector.verifier',
        );

        self::assertSame(
            14,
            $invite->getInviteId(),
        );
        self::assertSame(
            'selector.verifier',
            $invite->getToken(),
        );

        self::assertSame(
            8025,
            new ExportUserDataMessage(8025)->getLidnr(),
        );
    }

    public function testAPasswordResetRequestDefaultsToNoMembershipNumber(): void
    {
        $message = new PasswordResetRequestEmail(
            UserTypes::CompanyUser,
            'rep@example.com',
        );

        self::assertSame(
            UserTypes::CompanyUser,
            $message->getUserType(),
        );
        self::assertSame(
            'rep@example.com',
            $message->getEmail(),
        );
        self::assertNull($message->getMembershipNumber());

        self::assertSame(
            8025,
            new PasswordResetRequestEmail(
                UserTypes::User,
                'ada@example.com',
                8025,
            )->getMembershipNumber(),
        );
    }

    public function testTheSessionRevocationCarriesTheFirewallAndEverySeries(): void
    {
        $message = new RevokeSessionsRealtimeMessage(
            'main',
            [
                'aaa',
                'bbb',
            ],
        );

        self::assertSame(
            'main',
            $message->getFirewallName(),
        );
        self::assertSame(
            [
                'aaa',
                'bbb',
            ],
            $message->getSeries(),
        );
    }

    public function testASecurityNotificationCarriesEverythingTheWarningNeeds(): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-31 12:00:00');
        $message = new SecurityNotificationMessage(
            'main',
            '8025',
            NotificationType::SignIn,
            ['address' => '192.0.2.1'],
            $occurredAt,
        );

        self::assertSame(
            'main',
            $message->getFirewallName(),
        );
        self::assertSame(
            '8025',
            $message->getUserIdentifier(),
        );
        self::assertSame(
            NotificationType::SignIn,
            $message->getType(),
        );
        self::assertSame(
            ['address' => '192.0.2.1'],
            $message->getOrigin(),
        );
        self::assertSame(
            $occurredAt,
            $message->getOccurredAt(),
        );
    }
}
