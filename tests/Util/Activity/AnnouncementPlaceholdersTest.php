<?php

declare(strict_types=1);

namespace App\Tests\Util\Activity;

use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\Enums\AnnouncementPlaceholder;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Util\Activity\AnnouncementPlaceholders;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_intersect;
use function array_keys;
use function array_map;

/**
 * The vocabulary a bulk email may be written in. What matters here is that its two parts cannot collide: the names
 * the application defines are reserved whether or not the message being written can replace them, so a question
 * named after one of them is given a different token instead of replacing it.
 */
final class AnnouncementPlaceholdersTest extends TestCase
{
    public function testAQuestionNamedAfterTheActivityIsGivenATokenOfItsOwn(): void
    {
        $signupList = $this->listAsking(
            'Location',
            'Dietary requirements',
            'Location',
        );

        // {{LOCATION}} remains the activity's. Both questions are numbered by their position, which also
        // distinguishes them from each other.
        self::assertSame(
            [
                'LOCATION_1' => 'Location',
                'DIETARY_REQUIREMENTS' => 'Dietary requirements',
                'LOCATION_3' => 'Location',
            ],
            AnnouncementPlaceholders::questionsOf(
                $signupList,
                Languages::English,
            ),
        );
    }

    public function testNoQuestionCanTakeAReservedName(): void
    {
        $reserved = array_column(
            AnnouncementPlaceholder::cases(),
            'value',
        );

        // A sign-up list that asks a question named after every reserved token.
        $signupList = $this->listAsking(...array_map(
            static fn (AnnouncementPlaceholder $placeholder): string => $placeholder->value,
            AnnouncementPlaceholder::cases(),
        ));

        self::assertSame(
            [],
            array_intersect(
                array_keys(AnnouncementPlaceholders::questionsOf(
                    $signupList,
                    Languages::English,
                )),
                $reserved,
            ),
        );
    }

    public function testTheSignupListIsOnlyOfferedWhereTheMessageIsAddressedToOne(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        self::assertArrayHasKey(
            'SIGNUPLIST_NAME',
            AnnouncementPlaceholders::aboutFor(
                $this->listAsking(),
                $translator,
            ),
        );
        // A message to everybody on the activity covers all of its sign-up lists, so there is no single name.
        self::assertArrayNotHasKey(
            'SIGNUPLIST_NAME',
            AnnouncementPlaceholders::aboutFor(
                null,
                $translator,
            ),
        );
    }

    public function testAPlaceholderNothingAnswersToIsReportedAndLeftAsItWasWritten(): void
    {
        $offered = ['LOCATION' => 'Location'];
        $text = 'We are at {{LOCATION}}, ask for {{ORGAN_ABBR}} at {{ LOCATION }}.';

        self::assertSame(
            ['ORGAN_ABBR'],
            AnnouncementPlaceholders::unknownIn(
                $text,
                $offered,
            ),
        );
        self::assertSame(
            'We are at Room 2, ask for {{ORGAN_ABBR}} at Room 2.',
            AnnouncementPlaceholders::apply(
                $text,
                ['LOCATION' => 'Room 2'],
            ),
        );
    }

    /**
     * The composer is a Markdown editor, and an underscore is emphasis in Markdown, so a placeholder inserted there
     * arrives escaped. It has to be replaced, and reported when nothing replaces it, exactly as one typed by hand is.
     */
    public function testAPlaceholderTheComposerEscapedIsReadAsTheNameItStandsFor(): void
    {
        $text = 'We are at {{LOCATION}}, ask for {{ORGAN\_ABBR}} at {{ SIGNUPLIST\_NAME }}.';

        self::assertSame(
            ['ORGAN_ABBR'],
            AnnouncementPlaceholders::unknownIn(
                $text,
                [
                    'LOCATION' => 'Location',
                    'SIGNUPLIST_NAME' => 'Sign-up list',
                ],
            ),
        );
        self::assertSame(
            'We are at Room 2, ask for {{ORGAN\_ABBR}} at Participants.',
            AnnouncementPlaceholders::apply(
                $text,
                [
                    'LOCATION' => 'Room 2',
                    'SIGNUPLIST_NAME' => 'Participants',
                ],
            ),
        );
    }

    private function listAsking(string ...$questions): SignupList
    {
        $signupList = new SignupList();
        $signupList->setName(new ActivityLocalisedText(
            'Participants',
            'Deelnemers',
        ));

        foreach ($questions as $question) {
            $field = new SignupField();
            $field->setName(new ActivityLocalisedText(
                $question,
                $question,
            ));
            $signupList->addField($field);
        }

        return $signupList;
    }
}
