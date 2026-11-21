<?php

declare(strict_types=1);

namespace App\Tests\Util\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Util\Activity\SignupListRule;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function strtr;

/**
 * The form, the workflow guard and the review screen all have to agree on whether a sign-up list has been filled in.
 */
final class SignupListRuleTest extends TestCase
{
    public function testARevisionWithoutListsHasNothingUnfinished(): void
    {
        $revision = self::createStub(ActivityRevision::class);
        $revision->method('getSignupLists')->willReturn(new ArrayCollection());

        self::assertNull(SignupListRule::firstUnfinished($revision, []));
    }

    public function testAListWithANameIsLabelledWithIt(): void
    {
        self::assertSame(
            'First years',
            SignupListRule::label(
                $this->list('First years'),
                1,
                $this->translator(),
            ),
        );
    }

    public function testAListWithoutANameIsLabelledByItsPosition(): void
    {
        self::assertSame(
            'Sign-up list 2',
            SignupListRule::label(
                $this->list(''),
                2,
                $this->translator(),
            ),
        );
    }

    public function testAListWhoseNameIsOnlyWhitespaceIsLabelledByItsPosition(): void
    {
        self::assertSame(
            'Sign-up list 1',
            SignupListRule::label(
                $this->list('   '),
                1,
                $this->translator(),
            ),
        );
    }

    private function list(string $name): SignupList
    {
        $text = self::createStub(ActivityLocalisedText::class);
        $text->method('getText')->willReturn($name);

        $list = self::createStub(SignupList::class);
        $list->name = $text;

        return $list;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => strtr(
                $id,
                $parameters,
            ),
        );

        return $translator;
    }
}
