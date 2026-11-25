<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity;

use App\Entity\Activity\SignupList;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\ExternalSignupResendForm;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The form that re-sends a lost confirmation link. It does not require an open list, because a sign-up made while
 * the list was open can still be confirmed; it requires the activity's live list that accepts externals. A
 * successful submit needs a captcha and is tested in the browser suite.
 */
final class ExternalSignupResendFormTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get('security.token_storage')->setToken(null);
    }

    public function testAListThatDoesNotExistIsNotFound(): void
    {
        $component = self::getContainer()->get(ExternalSignupResendForm::class);

        $this->expectException(NotFoundHttpException::class);
        $component->mount(999999);
    }

    public function testAListOnlyGEWISMembersMayJoinSendsNoLinks(): void
    {
        $component = $this->formFor(6);

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    public function testAFrozenActivitySendsNoLinks(): void
    {
        $component = $this->formFor(3);
        $component->signupList->getActivity()->cancel($this->member(8005));

        $this->expectException(AccessDeniedException::class);
        $component->submit();
    }

    /**
     * A closed list is not a reason to refuse, because the link belongs to a sign-up made while it was open; here
     * the address is what fails.
     */
    public function testAnAddressThatIsNotOneKeepsTheFormOpenWithItsErrors(): void
    {
        $component = $this->formFor(3);
        self::assertTrue($component->signupList->isClosed());
        $component->formValues = [
            'email' => 'not-an-address',
            'security' => '',
        ];

        $this->expectException(UnprocessableEntityHttpException::class);
        $component->submit();
    }

    public function testTheFormOpensAgainAListThatIsStillOpen(): void
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find(3);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 day');

        $component = $this->formFor(3);

        self::assertSame(
            $list,
            $component->signupList,
        );
    }

    private function formFor(int $listId): ExternalSignupResendForm
    {
        $component = self::getContainer()->get(ExternalSignupResendForm::class);
        $component->mount($listId);

        return $component;
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
}
