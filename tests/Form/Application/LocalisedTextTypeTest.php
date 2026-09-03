<?php

declare(strict_types=1);

namespace App\Tests\Form\Application;

use App\Entity\Activity\ActivityLocalisedText;
use App\Form\Application\LocalisedTextType;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * A language that is switched off is disabled in the browser and so never handed in at all. That absence is no answer
 * rather than an erasure, which is what lets an activity's sign-up lists keep their Dutch names while the activity is
 * written in English alone.
 */
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class LocalisedTextTypeTest extends TypeTestCase
{
    public function testALanguageThatIsNotHandedInKeepsWhatItHad(): void
    {
        $text = $this->text();

        $this->factory->create(
            LocalisedTextType::class,
            $text,
        )->submit(['valueEN' => 'Dinner']);

        self::assertSame(
            'Diner',
            $text->getValueNL(),
        );
        self::assertSame(
            'Dinner',
            $text->getValueEN(),
        );
    }

    public function testALanguageThatIsHandedInEmptyIsCleared(): void
    {
        $text = $this->text();

        $this->factory->create(
            LocalisedTextType::class,
            $text,
        )->submit([
            'valueNL' => '',
            'valueEN' => 'Dinner',
        ]);

        self::assertNull($text->getValueNL());
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
     * @return list<LocalisedTextType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [new LocalisedTextType()];
    }

    private function text(): ActivityLocalisedText
    {
        $text = new ActivityLocalisedText();
        $text->updateValues(
            'Dinner',
            'Diner',
        );

        return $text;
    }
}
