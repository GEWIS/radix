<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use App\Entity\Activity\SignupList;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\ExternalSignupForm;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The guest sign-up form. A live request bypasses the page, so the rules for a guest on the activity's open,
 * non-members-only list are checked on submit. A successful submit needs a captcha and is tested in the browser
 * suite.
 *
 * Activity #4's list #3 accepts externals and closed weeks ago; list #6 (the Gala) is GEWIS-only.
 */
final class ExternalSignupFormTest extends DatabaseTestCase
{
    private const int GUEST_LIST = 3;
    private const int GEWIS_ONLY_LIST = 6;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('security.token_storage')->setToken(null);
    }

    public function testAListThatDoesNotExistIsNotFound(): void
    {
        $component = self::getContainer()->get(ExternalSignupForm::class);

        $this->expectException(NotFoundHttpException::class);
        $component->mount(999999);
    }

    public function testAFormForAListWithoutASensitiveQuestionIsNotFlagged(): void
    {
        $component = $this->formFor(self::GUEST_LIST);

        self::assertSame(
            self::GUEST_LIST,
            $component->signupList->id,
        );
        self::assertFalse($component->hasSensitiveField());
    }

    public function testAMemberCannotSignUpAsAGuest(): void
    {
        $this->signInMember();
        $component = $this->formFor($this->openGuestList());

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAListOnlyGEWISMembersMayJoinTakesNoGuests(): void
    {
        $component = $this->formFor(self::GEWIS_ONLY_LIST);
        $component->signupList->openDate = new DateTimeImmutable('-1 hour');
        $component->signupList->closeDate = new DateTimeImmutable('+1 day');

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAClosedListTakesNoMoreGuests(): void
    {
        // The seeded workshop closed four weeks ago.
        $component = $this->formFor(self::GUEST_LIST);

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAFrozenActivityTakesNoMoreGuests(): void
    {
        $component = $this->formFor($this->openGuestList());
        $component->signupList->getActivity()->cancel($this->member(8005));

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAnIncompleteSubmitKeepsTheFormOpenWithItsErrors(): void
    {
        $component = $this->formFor($this->openGuestList());
        $component->formValues = [];

        $this->expectException(UnprocessableEntityHttpException::class);
        $component->submit();
    }

    private function formFor(int $listId): ExternalSignupForm
    {
        $component = self::getContainer()->get(ExternalSignupForm::class);
        $component->mount($listId);

        return $component;
    }

    /**
     * The seeded guest list, opened so only the guest rules are left to decide the submit.
     */
    private function openGuestList(): int
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find(self::GUEST_LIST);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 day');

        return self::GUEST_LIST;
    }

    private function member(int $lidnr): Member
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user->member;
    }

    private function signInMember(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_USER'],
        ));
    }
}
