<?php

declare(strict_types=1);

namespace App\Tests\Form\Decision\OrganPage;

use App\Entity\Decision\OrganInformationRevision;
use App\Form\Decision\OrganPage\OrganPageData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_map;
use function iterator_to_array;
use function str_repeat;

#[CoversClass(OrganPageData::class)]
final class OrganPageDataTest extends TestCase
{
    /**
     * A body that has never written anything starts with both languages on, so neither is silently dropped the first
     * time it opens the form.
     */
    public function testANewPageStartsWithBothLanguagesEnabled(): void
    {
        $data = OrganPageData::fromRevision(new OrganInformationRevision());

        self::assertTrue($data->languageDutch);
        self::assertTrue($data->languageEnglish);
        self::assertSame(
            [],
            $data->labelIds,
        );
    }

    /**
     * The toggles follow what the page already contains: opening a Dutch-only page must not offer to drop its text, and
     * must not turn English on and then require a translation nobody wrote.
     */
    #[DataProvider('existingText')]
    public function testTheTogglesFollowTheLanguagesThePageIsWrittenIn(
        ?string $dutch,
        ?string $english,
        bool $dutchExpected,
        bool $englishExpected,
    ): void {
        $revision = new OrganInformationRevision();
        $revision->description->updateValues(
            $english,
            $dutch,
        );

        $data = OrganPageData::fromRevision($revision);

        self::assertSame(
            $dutchExpected,
            $data->languageDutch,
        );
        self::assertSame(
            $englishExpected,
            $data->languageEnglish,
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, bool, bool}>
     */
    public static function existingText(): iterable
    {
        yield 'nothing written yet' => [
            null,
            null,
            true,
            true,
        ];

        yield 'Dutch only' => [
            'Tekst',
            null,
            true,
            false,
        ];

        yield 'English only' => [
            null,
            'Text',
            false,
            true,
        ];

        yield 'both languages' => [
            'Tekst',
            'Text',
            true,
            true,
        ];

        yield 'a blank Dutch description' => [
            '   ',
            null,
            true,
            false,
        ];
    }

    /**
     * A language that is off keeps its stored text. The field is disabled and not submitted, so the revision must not
     * be overwritten with the empty form value.
     */
    public function testSwitchingALanguageOffKeepsTheStoredText(): void
    {
        $revision = new OrganInformationRevision();
        $revision->shortDescription->updateValues(
            'Short EN',
            'Kort NL',
        );
        $revision->description->updateValues(
            'Description EN',
            'Beschrijving NL',
        );

        $data = OrganPageData::fromRevision($revision);
        $data->languageEnglish = false;
        $data->shortDescriptionEN = 'ignored';
        $data->descriptionEN = 'ignored';
        $data->shortDescriptionNL = 'Nieuw kort';
        $data->descriptionNL = 'Nieuwe beschrijving';

        $data->applyTo($revision);

        self::assertSame(
            'Short EN',
            $revision->shortDescription->getValueEN(),
        );
        self::assertSame(
            'Description EN',
            $revision->description->getValueEN(),
        );
        self::assertSame(
            'Nieuw kort',
            $revision->shortDescription->getValueNL(),
        );
        self::assertSame(
            'Nieuwe beschrijving',
            $revision->description->getValueNL(),
        );
    }

    public function testAnEnabledLanguageIsWrittenEvenWhenItIsBlank(): void
    {
        $revision = new OrganInformationRevision();
        $revision->description->updateValues(
            'Old EN',
            'Old NL',
        );

        $data = OrganPageData::fromRevision($revision);
        $data->descriptionEN = null;
        $data->descriptionNL = '';
        $data->applyTo($revision);

        self::assertNull($revision->description->getValueEN());
        self::assertSame(
            '',
            $revision->description->getValueNL(),
        );
    }

    /**
     * With both languages off no per-language rule applies, so a page with no text at all would save without this
     * check. The violation is put on the Dutch toggle because that is the control the reader must change.
     */
    public function testAtLeastOneLanguageHasToStayOn(): void
    {
        $data = new OrganPageData();
        $data->languageDutch = false;
        $data->languageEnglish = false;

        $violations = $this->validatePage($data);

        self::assertCount(
            1,
            $violations,
        );
        self::assertArrayHasKey(
            0,
            $violations,
        );
        self::assertSame(
            'languageDutch',
            $violations[0]->getPropertyPath(),
        );
    }

    /**
     * Every enabled language needs both descriptions. A disabled language is not required, even though its fields are
     * still present in the request.
     */
    public function testEachEnabledLanguageNeedsBothDescriptions(): void
    {
        $data = new OrganPageData();
        $data->languageDutch = true;
        $data->languageEnglish = true;
        $data->shortDescriptionNL = 'Kort';
        $data->descriptionNL = ' ';
        $data->shortDescriptionEN = "   \n";
        $data->descriptionEN = 'Description';

        $paths = array_map(
            static fn ($violation): string => $violation->getPropertyPath(),
            iterator_to_array($this->validatePage($data)),
        );

        self::assertSame(
            [
                'descriptionNL',
                'shortDescriptionEN',
            ],
            $paths,
        );
    }

    public function testADisabledLanguageIsNotRequired(): void
    {
        $data = new OrganPageData();
        $data->languageDutch = true;
        $data->languageEnglish = false;
        $data->shortDescriptionNL = 'Kort';
        $data->descriptionNL = 'Beschrijving';

        self::assertCount(
            0,
            $this->validatePage($data),
        );
    }

    /**
     * The form's length limits are step-local: opening the contact step must not complain about a long description
     * that the page step owns.
     */
    public function testTheLengthLimitsBelongToThePageStep(): void
    {
        $data = new OrganPageData();
        $data->shortDescriptionNL = str_repeat(
            'a',
            OrganPageData::SHORT_DESCRIPTION_MAXIMUM + 1,
        );
        $data->shortDescriptionEN = 'Short';
        $data->descriptionNL = 'Beschrijving';
        $data->descriptionEN = 'Description';

        $validator = $this->validator();

        self::assertCount(
            1,
            $validator->validate(
                $data,
                groups: [OrganPageData::STEP_PAGE],
            ),
        );
        self::assertCount(
            0,
            $validator->validate(
                $data,
                groups: [OrganPageData::STEP_CONTACT],
            ),
        );
    }

    /**
     * @return list<ConstraintViolationInterface>
     */
    private function validatePage(OrganPageData $data): iterable
    {
        return iterator_to_array(
            $this->validator()->validate(
                $data,
                groups: [OrganPageData::STEP_PAGE],
            ),
            false,
        );
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
