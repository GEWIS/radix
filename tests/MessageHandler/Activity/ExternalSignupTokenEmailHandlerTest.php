<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\SignupList;
use App\Message\Activity\ExternalSignupTokenEmail;
use App\MessageHandler\Activity\ExternalSignupTokenEmailHandler;
use App\Repository\Activity\ExternalSignupRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The link an external subscriber receives to confirm or manage a sign-up. The plaintext token exists only in the
 * message and the mail, so the URL uses it, and the mail is in English because the recipient's locale is unknown.
 */
final class ExternalSignupTokenEmailHandlerTest extends TestCase
{
    public function testTheVerificationMailLinksTheTokenAndNamesTheActivityAndListInEnglish(): void
    {
        $sent = [];
        $this->handler(
            $this->signup(),
            $sent,
        )
            ->__invoke(new ExternalSignupTokenEmail(
                12,
                'selector.verifier',
                ExternalSignupVerificationPurpose::Verify,
            ));

        self::assertCount(
            1,
            $sent,
        );
        $email = $sent[0];
        self::assertSame(
            'ada@example.org',
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame(
            'Confirm your sign-up for Gala (Introduction)',
            $email->getSubject(),
        );
        self::assertSame(
            'emails/activity/external-signup-verification.html.twig',
            $email->getHtmlTemplate(),
        );
        self::assertSame(
            'https://gewis.nl/activity/external_signup_verify?token=selector.verifier',
            $email->getContext()['url'],
        );
        self::assertSame(
            'Ada Lovelace',
            $email->getContext()['name'],
        );
        self::assertSame(
            'Gala',
            $email->getContext()['activityName'],
        );
        self::assertSame(
            'Introduction',
            $email->getContext()['signupListName'],
        );
    }

    public function testTheManageMailUsesItsOwnSubjectTemplateAndRoute(): void
    {
        $sent = [];
        $this->handler(
            $this->signup(
                activityNameEn: null,
                activityNameNl: 'Gala (NL)',
                listNameEn: null,
                listNameNl: 'Introductie',
            ),
            $sent,
            'activity/external_signup_manage',
            'emails/activity/external-signup-manage.html.twig',
        )->__invoke(new ExternalSignupTokenEmail(
            12,
            'selector.verifier',
            ExternalSignupVerificationPurpose::Manage,
        ));

        self::assertCount(
            1,
            $sent,
        );
        // A text that was only written in Dutch is shown in Dutch rather than as an empty name.
        self::assertSame(
            'Manage your sign-up for Gala (NL) (Introductie)',
            $sent[0]->getSubject(),
        );
        self::assertSame(
            'emails/activity/external-signup-manage.html.twig',
            $sent[0]->getHtmlTemplate(),
        );
        self::assertSame(
            'https://gewis.nl/activity/external_signup_manage?token=selector.verifier',
            $sent[0]->getContext()['url'],
        );
    }

    public function testASignUpThatWasWithdrawnIsNotMailed(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');

        $handler = new ExternalSignupTokenEmailHandler(
            $mailer,
            $this->repository(null),
            $urlGenerator,
        );

        $handler->__invoke(new ExternalSignupTokenEmail(
            12,
            'selector.verifier',
            ExternalSignupVerificationPurpose::Verify,
        ));
    }

    /**
     * @param list<TemplatedEmail> $sent
     */
    private function handler(
        ExternalSignup $signup,
        array &$sent,
        string $route = 'activity/external_signup_verify',
        string $template = 'emails/activity/external-signup-verification.html.twig',
    ): ExternalSignupTokenEmailHandler {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sent): void {
                $sent[] = $email;
            },
        );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                $route,
                ['token' => 'selector.verifier'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://gewis.nl/' . $route . '?token=selector.verifier');

        return new ExternalSignupTokenEmailHandler(
            $mailer,
            $this->repository($signup),
            $urlGenerator,
        );
    }

    private function repository(?ExternalSignup $signup): ExternalSignupRepository
    {
        $repository = self::createStub(ExternalSignupRepository::class);
        $repository->method('find')->willReturn($signup);

        return $repository;
    }

    private function signup(
        ?string $activityNameEn = 'Gala',
        ?string $activityNameNl = 'Gala',
        ?string $listNameEn = 'Introduction',
        ?string $listNameNl = 'Introductie',
    ): ExternalSignup {
        $revision = new ActivityRevision();
        $activity = new Activity();
        $activity->addRevision($revision);
        $revision->name = new ActivityLocalisedText(
            $activityNameEn,
            $activityNameNl,
        );
        $activity->setCurrentRevision($revision);

        $list = new SignupList();
        $list->revision = $revision;
        $list->name = new ActivityLocalisedText(
            $listNameEn,
            $listNameNl,
        );

        $signup = new ExternalSignup();
        $signup->signupList = $list;
        $signup->setFullName('Ada Lovelace');
        $signup->setEmail('ada@example.org');

        return $signup;
    }
}
