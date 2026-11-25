<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use App\Entity\Activity\Enums\ExternalSignupVerificationPurpose;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\ExternalSignupVerification;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupOption;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\ExternalSignupManage;
use App\Util\Application\SplitToken;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function array_map;
use function array_values;
use function assert;

/**
 * The self-service page an external reaches through the emailed Manage link. There is no login, so the token is
 * resolved on every render and action. The tests cover what the page allows with a valid token and what it refuses
 * without one.
 *
 * Activity #4's list #3 has one external, Alex Visitor.
 */
final class ExternalSignupManageTest extends DatabaseTestCase
{
    private const int LIST = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pushRequest();
    }

    public function testATokenThatIsNotOneIsNotFound(): void
    {
        $component = self::getContainer()->get(ExternalSignupManage::class);

        $this->expectException(NotFoundHttpException::class);
        $component->mount('not-a-token');
    }

    public function testATokenForAnotherPurposeIsNotFound(): void
    {
        $signup = $this->externalSignup();
        $token = $this->mint(
            $signup,
            ExternalSignupVerificationPurpose::Verify,
        );
        $component = self::getContainer()->get(ExternalSignupManage::class);

        $this->expectException(NotFoundHttpException::class);
        $component->mount($token);
    }

    public function testTheTokenResolvesToItsSignup(): void
    {
        $signup = $this->externalSignup();
        $component = self::getContainer()->get(ExternalSignupManage::class);
        $component->mount($this->mint($signup, ExternalSignupVerificationPurpose::Manage));

        self::assertSame(
            $signup,
            $component->signup(),
        );
        self::assertSame(
            $signup,
            $component->signup(),
            'The token is resolved once per request.',
        );
    }

    public function testAnOpenListMayBeEdited(): void
    {
        $signup = $this->externalSignup();
        $this->openList();
        $component = $this->componentFor($signup);

        self::assertTrue($component->isEditable());

        $field = $signup->signupList->getFields()->get(1);
        self::assertInstanceOf(
            SignupField::class,
            $field,
        );
        $option = $field->getOptions()->first();
        self::assertInstanceOf(
            SignupOption::class,
            $option,
        );

        $component->formValues = [
            'fullName' => 'Alexandra Visitor',
            'field_1' => 'No gluten at all',
            'field_2' => (int) $option->id,
        ];
        $component->save();

        self::assertSame(
            'Your sign-up has been updated.',
            $component->feedback,
        );
        self::assertSame(
            'Alexandra Visitor',
            $signup->getFullName(),
        );
        self::assertSame(
            'No gluten at all',
            $signup->toFormArray()[1] ?? null,
        );
    }

    public function testAClosedListCannotBeEdited(): void
    {
        $signup = $this->externalSignup();
        $component = $this->componentFor($signup);

        self::assertFalse($component->isEditable());

        $component->formValues = ['fullName' => 'Somebody Else'];
        $component->save();

        self::assertNull($component->feedback);
        self::assertSame(
            'Alex Visitor',
            $signup->getFullName(),
        );
    }

    public function testUnsubscribingWithdrawsAndSendsTheSubscriberToTheActivity(): void
    {
        $signup = $this->externalSignup();
        $this->openList();
        $component = $this->componentFor($signup);

        $response = $component->unsubscribe();

        self::assertSame(
            self::getContainer()->get('router')->generate(
                'activity/view',
                ['activity' => $signup->signupList->getActivity()->id],
            ),
            $response->getTargetUrl(),
        );
        self::assertSame(
            ['You have been unsubscribed.'],
            $this->flashes('success'),
        );
    }

    /**
     * Withdrawing deletes the Manage token, so the page redirects instead of re-rendering; a closed list sends the
     * subscriber to the activity without withdrawing them.
     */
    public function testUnsubscribingFromAClosedListStillLeavesThePage(): void
    {
        $signup = $this->externalSignup();
        $component = $this->componentFor($signup);

        $response = $component->unsubscribe();

        self::assertSame(
            self::getContainer()->get('router')->generate(
                'activity/view',
                ['activity' => $signup->signupList->getActivity()->id],
            ),
            $response->getTargetUrl(),
        );
        self::assertSame(
            [],
            $this->flashes('success'),
        );
    }

    private function componentFor(ExternalSignup $signup): ExternalSignupManage
    {
        $component = self::getContainer()->get(ExternalSignupManage::class);
        $component->mount($this->mint($signup, ExternalSignupVerificationPurpose::Manage));

        return $component;
    }

    /**
     * A Manage token as the sign-up flow issues it: the selector and hash are stored, and the plaintext token is the
     * one in the email.
     */
    private function mint(
        ExternalSignup $signup,
        ExternalSignupVerificationPurpose $purpose,
    ): string {
        $split = SplitToken::generate(
            16,
            32,
            ExternalSignupVerification::HASH_ALGO,
        );

        $this->entityManager->persist(new ExternalSignupVerification(
            $signup,
            $purpose,
            $split['selector'],
            $split['hashedToken'],
            new DateTimeImmutable('now')->add(new DateInterval('P1D')),
        ));
        $this->entityManager->flush();

        return $split['token'];
    }

    private function externalSignup(): ExternalSignup
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find(self::LIST);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        foreach ($list->getSignUps() as $signup) {
            if ($signup instanceof ExternalSignup) {
                return $signup;
            }
        }

        self::fail('The seeded workshop has an external on its list.');
    }

    private function openList(): SignupList
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find(self::LIST);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 day');

        return $list;
    }

    private function pushRequest(): void
    {
        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get('request_stack')->push($request);
    }

    /**
     * @return list<string>
     */
    private function flashes(string $type): array
    {
        $request = self::getContainer()->get('request_stack')->getCurrentRequest();
        assert(null !== $request);
        $session = $request->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);

        return array_map(
            static fn (mixed $flash): string => (string) $flash,
            array_values($session->getFlashBag()->get($type)),
        );
    }
}
