<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Activity;

use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\ExternalSignupVerification;
use App\Entity\Activity\SignupList;
use App\Message\Activity\ExternalSignupResendVerificationEmail;
use App\Message\Activity\ExternalSignupTokenEmail;
use App\MessageHandler\Activity\ExternalSignupResendVerificationEmailHandler;
use App\Repository\Activity\ExternalSignupRepository;
use App\Repository\Activity\ExternalSignupVerificationRepository;
use App\Repository\Activity\SignupListRepository;
use App\Repository\Activity\SignupRepository;
use App\Service\Activity\SignupManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Resending a confirmation mail recovers a lost double-opt-in link. The existence check runs in the worker, so the
 * request cannot reveal whether an address is signed up; a withdrawn or confirmed sign-up does nothing.
 */
final class ExternalSignupResendVerificationEmailHandlerTest extends TestCase
{
    public function testAPendingSignUpHasItsTokenReplacedAndTheMailQueued(): void
    {
        $signup = new ExternalSignup();
        $signup->setFullName('Ada Lovelace');
        $signup->setEmail('ada@example.org');
        $list = new SignupList();

        $externalSignups = $this->createMock(ExternalSignupRepository::class);
        $externalSignups->expects(self::once())
            ->method('findOneByListAndEmail')
            ->with(
                $list,
                'ada@example.org',
            )
            ->willReturn($signup);

        $verifications = $this->createMock(ExternalSignupVerificationRepository::class);
        $verifications->expects(self::once())
            ->method('hasPendingVerification')
            ->with($signup)
            ->willReturn(true);
        // The old link must stop working, so every token of this sign-up is dropped before the new one is issued.
        $verifications->expects(self::once())
            ->method('deleteAllForSignup')
            ->with($signup);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(ExternalSignupVerification::class));
        $entityManager->expects(self::once())->method('flush');

        $dispatched = [];
        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $this->handler(
            $list,
            $externalSignups,
            $verifications,
            $entityManager,
            $messageBus,
        )->__invoke(new ExternalSignupResendVerificationEmail(42, 'ada@example.org'));

        self::assertCount(
            1,
            $dispatched,
        );
        $message = $dispatched[0];
        self::assertInstanceOf(
            ExternalSignupTokenEmail::class,
            $message,
        );
        self::assertSame(
            ExternalSignupVerificationPurpose::Verify,
            $message->getPurpose(),
        );
        // Only the hash is stored, so the message must contain the plaintext token for the link to exist.
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{32}\.[0-9a-f]{64}\z/',
            $message->getToken(),
        );
    }

    public function testAConfirmedSignUpIsNotMailedAgain(): void
    {
        $signup = new ExternalSignup();
        $signup->setFullName('Ada Lovelace');
        $signup->setEmail('ada@example.org');

        $externalSignups = self::createStub(ExternalSignupRepository::class);
        $externalSignups->method('findOneByListAndEmail')->willReturn($signup);

        $verifications = $this->createMock(ExternalSignupVerificationRepository::class);
        $verifications->expects(self::once())
            ->method('hasPendingVerification')
            ->willReturn(false);
        $verifications->expects(self::never())->method('deleteAllForSignup');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $this->handler(
            new SignupList(),
            $externalSignups,
            $verifications,
            $entityManager,
            $messageBus,
        )->__invoke(new ExternalSignupResendVerificationEmail(42, 'ada@example.org'));
    }

    public function testAnAddressThatIsNotSignedUpIsSilentlyIgnored(): void
    {
        $externalSignups = self::createStub(ExternalSignupRepository::class);
        $externalSignups->method('findOneByListAndEmail')->willReturn(null);

        $verifications = $this->createMock(ExternalSignupVerificationRepository::class);
        $verifications->expects(self::never())->method('hasPendingVerification');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $this->handler(
            new SignupList(),
            $externalSignups,
            $verifications,
            $entityManager,
            $messageBus,
        )->__invoke(new ExternalSignupResendVerificationEmail(42, 'nobody@example.org'));
    }

    public function testAListThatNoLongerExistsDoesNothingAtAll(): void
    {
        $signupLists = self::createStub(SignupListRepository::class);
        $signupLists->method('find')->willReturn(null);

        // The existence check would be a lookup for an address the request could time, so a missing list returns
        // before it.
        $externalSignups = $this->createMock(ExternalSignupRepository::class);
        $externalSignups->expects(self::never())->method('findOneByListAndEmail');

        $handler = new ExternalSignupResendVerificationEmailHandler(
            $signupLists,
            new SignupManager(
                self::createStub(EntityManagerInterface::class),
                self::createStub(MessageBusInterface::class),
                self::createStub(ExternalSignupVerificationRepository::class),
                $externalSignups,
                self::createStub(SignupRepository::class),
            ),
        );

        $handler->__invoke(new ExternalSignupResendVerificationEmail(404, 'ada@example.org'));
    }

    private function handler(
        SignupList $signupList,
        ExternalSignupRepository $externalSignups,
        ExternalSignupVerificationRepository $verifications,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
    ): ExternalSignupResendVerificationEmailHandler {
        $signupLists = self::createStub(SignupListRepository::class);
        $signupLists->method('find')->willReturn($signupList);

        return new ExternalSignupResendVerificationEmailHandler(
            $signupLists,
            new SignupManager(
                $entityManager,
                $messageBus,
                $verifications,
                $externalSignups,
                self::createStub(SignupRepository::class),
            ),
        );
    }
}
