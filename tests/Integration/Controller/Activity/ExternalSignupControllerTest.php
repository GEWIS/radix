<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\ExternalSignupController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\ExternalSignupVerification;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Util\Application\SplitToken;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The login-less half of external sign-ups: the double opt-in page, its confirmation and the manage page. Fetching a
 * link never changes a sign-up, which is why confirmation is a POST.
 *
 * The token is built here because the seed stores only the hash.
 */
final class ExternalSignupControllerTest extends DatabaseTestCase
{
    public function testTheVerificationPageShowsWhatIsBeingConfirmed(): void
    {
        $activity = $this->anActivityTakingSignups();
        $token = $this->aToken(
            $this->aPendingSignup($this->anOpenList($activity)),
            ExternalSignupVerificationPurpose::Verify,
        );
        $this->pushRequest();

        $response = $this->controller()->verify($token);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Symposium',
            $content,
        );
        self::assertStringContainsString(
            $token,
            $content,
        );
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->verify(SplitToken::generate(16, 32, ExternalSignupVerification::HASH_ALGO)['token']);
    }

    public function testATokenOfAnotherPurposeIsNotFound(): void
    {
        $activity = $this->anActivityTakingSignups();
        $token = $this->aToken(
            $this->aPendingSignup($this->anOpenList($activity)),
            ExternalSignupVerificationPurpose::Manage,
        );

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->verify($token);
    }

    public function testAnExpiredTokenIsNotFound(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
            new DateInterval('PT1M'),
            '-1 minute',
        );

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->verify($token);
    }

    public function testAFetchedLinkDoesNotConfirmTheSignup(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
        );
        $this->pushRequest();

        $this->controller()->verify($token);

        self::assertNull(
            $signup->verifiedAt,
            'Opening the link is a read; only the form may confirm.',
        );
        self::assertNotNull($this->verificationFor($signup, ExternalSignupVerificationPurpose::Verify));
    }

    public function testConfirmingConsumesTheTokenAndHandsOverTheManageLink(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
        );

        $session = $this->pushRequest();

        $response = $this->controller()->confirm($token);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/activities/view/' . $activity->id,
            $response->getTargetUrl(),
        );
        self::assertNotNull($signup->verifiedAt);
        self::assertNotEmpty($session->getFlashBag()->get('success'));

        $this->entityManager->clear();
        self::assertNull($this->verificationFor($signup, ExternalSignupVerificationPurpose::Verify));
        self::assertNotNull(
            $this->verificationFor(
                $signup,
                ExternalSignupVerificationPurpose::Manage,
            ),
            'Confirming is expected to issue the self-service link.',
        );
    }

    public function testConfirmingACancelledActivityIsRefused(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
        );

        $activity->cancel($this->board()->member);
        $this->entityManager->flush();

        $session = $this->pushRequest();

        $response = $this->controller()->confirm($token);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/activities/view/' . $activity->id,
            $response->getTargetUrl(),
        );
        self::assertNull($signup->verifiedAt);
        self::assertNotNull($this->verificationFor($signup, ExternalSignupVerificationPurpose::Verify));
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    /**
     * An unpublished activity's own page 404s, so the visitor is sent to the overview instead.
     */
    public function testConfirmingAnUnpublishedActivityGoesToTheOverview(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
        );

        $activity->unpublish($this->board()->member);
        $this->entityManager->flush();

        $session = $this->pushRequest();

        $response = $this->controller()->confirm($token);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringEndsWith(
            '/activities',
            $response->getTargetUrl(),
        );
        self::assertNull($signup->verifiedAt);
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Warning->value));
    }

    public function testConfirmingAnUnknownTokenIsNotFound(): void
    {
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->confirm(SplitToken::generate(16, 32, ExternalSignupVerification::HASH_ALGO)['token']);
    }

    public function testTheManagePageRendersWhatCanBeChanged(): void
    {
        $activity = $this->anActivityTakingSignups();
        $signup = $this->aPendingSignup($this->anOpenList($activity));
        $token = $this->aToken(
            $signup,
            ExternalSignupVerificationPurpose::Manage,
        );
        $this->pushRequest();

        $response = $this->controller()->manage($token);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Symposium',
            $content,
        );
        self::assertStringContainsString(
            $token,
            $content,
        );
    }

    public function testTheManagePageRefusesAVerificationToken(): void
    {
        $activity = $this->anActivityTakingSignups();
        $token = $this->aToken(
            $this->aPendingSignup($this->anOpenList($activity)),
            ExternalSignupVerificationPurpose::Verify,
        );

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->manage($token);
    }

    /**
     * The seeded upcoming symposium: published, and its "Talks" list is open to externals for weeks around today.
     */
    private function anActivityTakingSignups(): Activity
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $candidate) {
            if (
                'Symposium' !== $candidate->getLiveRevision()?->name->getValueEN()
                || $candidate->isFrozen()
            ) {
                continue;
            }

            return $candidate;
        }

        self::fail('The seed is expected to contain the upcoming symposium.');
    }

    private function anOpenList(Activity $activity): SignupList
    {
        foreach ($activity->getLiveSignupLists() as $list) {
            if (
                $list->isOpen()
                && !$list->onlyGEWIS
            ) {
                return $list;
            }
        }

        self::fail('The seeded activity is expected to have an open list for externals.');
    }

    private function aPendingSignup(SignupList $list): ExternalSignup
    {
        $signup = new ExternalSignup();
        $signup->signupList = $list;
        $signup->setFullName('Guido Gast');
        $signup->setEmail('guido.gast@example.org');

        $this->entityManager->persist($signup);
        $this->entityManager->flush();

        return $signup;
    }

    private function aToken(
        ExternalSignup $signup,
        ExternalSignupVerificationPurpose $purpose,
        ?DateInterval $validFor = null,
        string $expiresAt = '+1 hour',
    ): string {
        $generated = SplitToken::generate(
            16,
            32,
            ExternalSignupVerification::HASH_ALGO,
        );

        $expiry = new DateTimeImmutable($expiresAt);
        if (null !== $validFor) {
            $expiry = $expiry->add($validFor);
        }

        $verification = new ExternalSignupVerification(
            $signup,
            $purpose,
            $generated['selector'],
            $generated['hashedToken'],
            $expiry,
        );

        $this->entityManager->persist($verification);
        $this->entityManager->flush();

        return $generated['token'];
    }

    private function verificationFor(
        ExternalSignup $signup,
        ExternalSignupVerificationPurpose $purpose,
    ): ?ExternalSignupVerification {
        return $this->entityManager->getRepository(ExternalSignupVerification::class)->findOneBy([
            'externalSignup' => $signup,
            'purpose' => $purpose,
        ]);
    }

    private function controller(): ExternalSignupController
    {
        return self::getContainer()->get(ExternalSignupController::class);
    }

    private function pushRequest(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    /**
     * The member a freeze is recorded against; the actions themselves need no signed-in user.
     */
    private function board(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
