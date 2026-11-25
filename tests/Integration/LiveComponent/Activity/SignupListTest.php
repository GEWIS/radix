<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList as SignupListEntity;
use App\Entity\Activity\SignupOption;
use App\Entity\Activity\UserSignup;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Repository\Activity\UserSignupRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\SignupList;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_filter;
use function assert;

/**
 * The member-facing panel of one sign-up list: signing up, editing answers and withdrawing. Each action re-checks
 * the sign-up window, because a live request bypasses the page that embedded the component.
 *
 * List #3 has two questions, 8005 and 8006 admitted, 8015 an admitted no-show and 8155 waiting. The list is in the
 * past, so tests that need an open window move the dates into the present.
 */
final class SignupListTest extends DatabaseTestCase
{
    private const int WORKSHOP_LIST = 3;
    private const int ADMITTED_MEMBER = 8005;
    private const int ABSENT_MEMBER = 8007;

    public function testAListThatDoesNotExistIsNotFound(): void
    {
        $component = self::getContainer()->get(SignupList::class);

        $this->expectException(NotFoundHttpException::class);
        $component->mount(999999);
    }

    public function testThePanelShowsTheListAndTheMembersOwnSignup(): void
    {
        $this->authenticate(self::ADMITTED_MEMBER);
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $view = $component->listView();

        self::assertSame(
            $component->signupList->id,
            $view->listId,
        );
        // Four member sign-ups and one external, all of them on the list.
        self::assertSame(
            5,
            $view->subscriberCount,
        );
        self::assertTrue($view->viewerHasSignup);
        self::assertTrue($component->isEditing());
        self::assertFalse($component->hasSensitiveField());
    }

    public function testAMemberWhoHasNotSignedUpIsShownTheEmptyForm(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $component = $this->componentFor(self::WORKSHOP_LIST);

        self::assertFalse($component->isEditing());
        self::assertFalse($component->listView()->viewerHasSignup);
    }

    public function testSigningUpAddsTheMemberAndTheirAnswers(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $list = $this->openList();
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $component->formValues = $this->answers(
            $list,
            'No nuts',
        );
        $component->submit();

        self::assertSame(
            'You are signed up.',
            $component->feedback,
        );
        $signup = $this->signupOf(self::ABSENT_MEMBER);
        self::assertNotNull($signup);
        // The list is limited and already drawn, so everybody who joins now joins the waiting list.
        self::assertFalse($signup->drawn);
        self::assertSame(
            'No nuts',
            $signup->toFormArray()[1] ?? null,
        );
    }

    public function testEditingReplacesTheAnswersTheMemberGave(): void
    {
        $this->authenticate(self::ADMITTED_MEMBER);
        $list = $this->openList();
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $component->formValues = $this->answers(
            $list,
            'Vegan',
        );
        $component->submit();

        self::assertSame(
            'Your sign-up has been updated.',
            $component->feedback,
        );
        $signup = $this->signupOf(self::ADMITTED_MEMBER);
        self::assertNotNull($signup);
        self::assertSame(
            'Vegan',
            $signup->toFormArray()[1] ?? null,
        );
        // Editing is not a second sign-up: the member is still on the list once.
        self::assertCount(
            1,
            array_filter(
                $list->getSignUps()->toArray(),
                static fn (Signup $candidate): bool => $candidate instanceof UserSignup
                    && self::ADMITTED_MEMBER === $candidate->user->lidnr,
            ),
        );
    }

    public function testAnIncompleteAnswerKeepsTheFormOpenWithItsErrors(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $component = $this->componentFor(self::WORKSHOP_LIST);
        $component->formValues = $this->answers(
            $this->openList(),
            '',
        );

        $this->expectException(UnprocessableEntityHttpException::class);
        $component->submit();
    }

    public function testSigningUpAfterTheWindowClosedIsRefused(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $component = $this->componentFor(self::WORKSHOP_LIST);
        $component->formValues = $this->answers(
            $component->signupList,
            'No nuts',
        );

        // The seeded list closed four weeks ago.
        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAFrozenActivityRefusesASignUpEvenWhileTheWindowIsOpen(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $list = $this->openList();
        $component = $this->componentFor(self::WORKSHOP_LIST);
        $component->formValues = $this->answers(
            $list,
            'No nuts',
        );
        $list->getActivity()->cancel($this->member(self::ABSENT_MEMBER));

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testWithdrawingTakesTheMemberOffTheList(): void
    {
        $this->authenticate(self::ADMITTED_MEMBER);
        $this->openList();
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $component->withdraw();

        self::assertSame(
            'You have been unsubscribed.',
            $component->feedback,
        );
        self::assertNull($this->signupOf(self::ADMITTED_MEMBER));
    }

    public function testWithdrawingWithoutASignUpIsRefused(): void
    {
        $this->authenticate(self::ABSENT_MEMBER);
        $this->openList();
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $this->expectException(AccessDeniedException::class);
        $component->withdraw();
    }

    public function testWithdrawingAfterTheWindowClosedIsRefused(): void
    {
        $this->authenticate(self::ADMITTED_MEMBER);
        $component = $this->componentFor(self::WORKSHOP_LIST);

        $this->expectException(AccessDeniedException::class);
        $component->withdraw();
    }

    /**
     * The answers the form takes, keyed as the form's own children: one per question the list asks, valid for its
     * type, plus the policy agreement.
     *
     * @return array<string, mixed>
     */
    private function answers(
        SignupListEntity $list,
        string $text,
    ): array {
        $values = ['agree' => true];

        foreach ($list->getFields() as $field) {
            assert($field instanceof SignupField);
            $values['field_' . $field->id] = match ($field->type) {
                SignupFieldTypes::Text => $text,
                SignupFieldTypes::Number => 2,
                SignupFieldTypes::YesNo => '1',
                SignupFieldTypes::Choice => (int) self::firstOptionId($field),
            };
        }

        return $values;
    }

    /**
     * The seeded list is in the past, so a test that needs an open window moves it into one; the write is rolled back
     * with the test.
     */
    private function openList(): SignupListEntity
    {
        $list = $this->entityManager->getRepository(SignupListEntity::class)->find(self::WORKSHOP_LIST);
        self::assertInstanceOf(
            SignupListEntity::class,
            $list,
        );

        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 day');

        return $list;
    }

    private function signupOf(int $lidnr): ?UserSignup
    {
        return self::getContainer()->get(UserSignupRepository::class)->findOneByListAndMember(
            $this->openList(),
            $this->member($lidnr),
        );
    }

    private function componentFor(int $listId): SignupList
    {
        $component = self::getContainer()->get(SignupList::class);
        $component->mount($listId);

        return $component;
    }

    private function member(int $lidnr): Member
    {
        return $this->user($lidnr)->member;
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function authenticate(int $lidnr): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_USER'],
        ));
    }

    private static function firstOptionId(SignupField $field): ?int
    {
        $option = $field->getOptions()->first();
        self::assertInstanceOf(
            SignupOption::class,
            $option,
        );

        return $option->id;
    }
}
