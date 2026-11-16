<?php

declare(strict_types=1);

namespace App\DataFixtures\Decision;

use App\Entity\Decision\DecisionLocalisedText;
use App\Entity\Decision\OrganLabel;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

/**
 * The labels a body's page can carry, as the board would set them up: the four pillars of the association and the
 * first-year committees, plus one that is retired, so the seed covers the retired state.
 *
 * The reference keys let other fixtures (OrganInformationFixture) attach labels to pages.
 */
class OrganLabelFixture extends Fixture implements FixtureGroupInterface
{
    public const string REFERENCE_EDUCATION = 'organ-label-education';
    public const string REFERENCE_CAREER = 'organ-label-career';
    public const string REFERENCE_PERSONAL_DEVELOPMENT = 'organ-label-personal-development';
    public const string REFERENCE_FUN = 'organ-label-fun';
    public const string REFERENCE_FIRST_YEAR = 'organ-label-first-year';
    public const string REFERENCE_RETIRED = 'organ-label-retired';

    #[Override]
    public function load(ObjectManager $manager): void
    {
        $labels = [
            self::REFERENCE_EDUCATION => [
                'en' => 'Education',
                'nl' => 'Opleiding',
            ],
            self::REFERENCE_CAREER => [
                'en' => 'Career',
                'nl' => 'Carrière',
            ],
            self::REFERENCE_PERSONAL_DEVELOPMENT => [
                'en' => 'Personal development',
                'nl' => 'Persoonlijke ontwikkeling',
            ],
            self::REFERENCE_FUN => [
                'en' => 'Fun',
                'nl' => 'Leven',
            ],
            self::REFERENCE_FIRST_YEAR => [
                'en' => 'FYC',
                'nl' => 'Eerstejaars',
            ],
            self::REFERENCE_RETIRED => [
                'en' => 'Corona period',
                'nl' => 'Coronaperiode',
                'retired' => true,
            ],
        ];

        foreach ($labels as $reference => $data) {
            $label = new OrganLabel();
            $label->name = new DecisionLocalisedText(
                $data['en'],
                $data['nl'],
            );

            if (true === ($data['retired'] ?? false)) {
                $label->retire();
            }

            $manager->persist($label);
            $this->addReference(
                $reference,
                $label,
            );
        }

        $manager->flush();
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['web'];
    }
}
