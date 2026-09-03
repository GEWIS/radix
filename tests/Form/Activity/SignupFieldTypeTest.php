<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\Enums\SignupFieldTypes;
use App\Entity\Activity\SignupField;
use App\Form\Activity\SignupFieldType;
use App\Form\Activity\SignupOptionType;
use App\Form\Application\LocalisedTextType;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * A number question is answered within bounds, so both are asked for and only for that type; the editor draws the
 * same rule by revealing the pair for the number type alone. These pin it on the server, where a submission that
 * skipped the editor also lands.
 */
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class SignupFieldTypeTest extends TypeTestCase
{
    public function testANumberQuestionMustNameBothBounds(): void
    {
        $form = $this->submitField(['type' => SignupFieldTypes::Number->value]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('minimumValue')->getErrors(),
        );
        self::assertNotCount(
            0,
            $form->get('maximumValue')->getErrors(),
        );
    }

    public function testANumberQuestionCannotEndBelowWhereItStarts(): void
    {
        $form = $this->submitField([
            'type' => SignupFieldTypes::Number->value,
            'minimumValue' => '10',
            'maximumValue' => '2',
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('maximumValue')->getErrors(),
        );
    }

    public function testANumberQuestionWithBothBoundsIsAccepted(): void
    {
        $form = $this->submitField([
            'type' => SignupFieldTypes::Number->value,
            'minimumValue' => '1',
            'maximumValue' => '10',
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    /**
     * The bounds belong to the number type alone: another type is not asked for them, and anything a type change left
     * behind is dropped rather than carried into the next revision.
     */
    public function testAnotherTypeIsAskedForNoBoundsAndKeepsNone(): void
    {
        $field = new SignupField();
        $form = $this->submitField(
            [
                'type' => SignupFieldTypes::Text->value,
                'minimumValue' => '1',
                'maximumValue' => '10',
            ],
            $field,
        );

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
        self::assertNull($field->getMinimumValue());
        self::assertNull($field->getMaximumValue());
    }

    /**
     * @return list<FormExtensionInterface>
     */
    #[Override]
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<SignupFieldType|LocalisedTextType|SignupOptionType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [
            new SignupFieldType(),
            new LocalisedTextType(),
            new SignupOptionType(),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return FormInterface<mixed>
     */
    private function submitField(
        array $overrides,
        ?SignupField $field = null,
    ): FormInterface {
        $form = $this->factory->create(
            SignupFieldType::class,
            $field ?? new SignupField(),
        );

        $form->submit($overrides + [
            'name' => [
                'valueNL' => 'Vraag',
                'valueEN' => 'Question',
            ],
            'type' => SignupFieldTypes::Text->value,
            'position' => '0',
            'options' => [],
        ]);

        return $form;
    }
}
