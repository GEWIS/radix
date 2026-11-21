<?php

declare(strict_types=1);

namespace App\Tests\Form\Decision\OrganPage;

use App\Entity\Decision\DecisionLocalisedText;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\Decision\OrganLabel;
use App\Form\Application\LocalisedTextType;
use App\Form\Application\SocialLinksType;
use App\Form\Decision\OrganPage\ContactStepType;
use App\Form\Decision\OrganPage\ImagesStepType;
use App\Form\Decision\OrganPage\OrganPageData;
use App\Form\Decision\OrganPage\OrganPageFlowType;
use App\Form\Decision\OrganPage\PageStepType;
use App\Repository\Decision\OrganLabelRepository;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;
use Symfony\UX\Autocomplete\Checksum\ChecksumCalculator;
use Symfony\UX\Autocomplete\Form\AutocompleteChoiceTypeExtension;

use function array_map;
use function assert;

#[CoversClass(OrganPageFlowType::class)]
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class OrganPageFlowTypeTest extends TypeTestCase
{
    /**
     * A body's page is edited in three steps, and the page step comes first because nothing else can be shown without
     * the text.
     */
    public function testThePageIsEditedInThreeSteps(): void
    {
        $flow = $this->flow();

        self::assertSame(
            [
                OrganPageData::STEP_PAGE,
                OrganPageData::STEP_CONTACT,
                OrganPageData::STEP_IMAGES,
            ],
            $flow->getCursor()->getSteps(),
        );
    }

    /**
     * Labels group committees by what they offer. A body that cannot carry labels is not offered the picker, which
     * the controller would ignore.
     */
    public function testTheLabelPickerIsOnlyOfferedWhenTheBodyCanCarryLabels(): void
    {
        self::assertFalse($this->flow()->get(OrganPageData::STEP_PAGE)->has('labelIds'));
        self::assertTrue(
            $this->flow(['labels_enabled' => true])
                ->get(OrganPageData::STEP_PAGE)
                ->has('labelIds'),
        );
    }

    /**
     * The picker starts from the labels the revision already carries, read from the data object, so a request cannot
     * open the form without them and drop them on save.
     */
    public function testThePickerStartsFromTheLabelsTheRevisionAlreadyCarries(): void
    {
        $data = OrganPageData::fromRevision($this->revisionWithLabels());

        $field = $this->flow(
            ['labels_enabled' => true],
            $data,
        )->get(OrganPageData::STEP_PAGE)->get('labelIds');

        self::assertSame(
            [7],
            $field->getConfig()->getOption('choices'),
        );
    }

    public function testTheContactStepAsksHowToReachTheBody(): void
    {
        $step = $this->factory->create(
            ContactStepType::class,
            new OrganPageData(),
        );

        foreach (
            [
                'email',
                'website',
                'socialLinks',
            ] as $field
        ) {
            self::assertTrue(
                $step->has($field),
                $field,
            );
        }
    }

    /**
     * The images are unmapped files: the controller stores them and puts the path on the revision, so a form that
     * mapped them would try to bind a string path to an upload.
     */
    public function testTheImagesAreCollectedUnmapped(): void
    {
        $step = $this->factory->create(
            ImagesStepType::class,
            new OrganPageData(),
        );

        foreach (
            [
                'bannerFile',
                'logoFile',
                'bannerCropData',
                'logoCropData',
            ] as $field
        ) {
            self::assertTrue(
                $step->has($field),
                $field,
            );
            self::assertFalse($step->get($field)->getConfig()->getMapped());
        }
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
     * @return list<AutocompleteChoiceTypeExtension>
     */
    #[Override]
    protected function getTypeExtensions(): array
    {
        return [
            new AutocompleteChoiceTypeExtension(new ChecksumCalculator('test-secret')),
        ];
    }

    /**
     * @return list<OrganPageFlowType|PageStepType|ContactStepType|ImagesStepType|SocialLinksType|LocalisedTextType>
     */
    #[Override]
    protected function getTypes(): array
    {
        $labels = self::createStub(OrganLabelRepository::class);
        $labels->method('findActiveWithName')->willReturnCallback(
            static fn (array $ids): array => array_map(
                static function (int $id): OrganLabel {
                    $label = new OrganLabel();
                    $label->name = new DecisionLocalisedText(
                        'Fun',
                        'Fun',
                    );
                    new ReflectionProperty(
                        OrganLabel::class,
                        'id',
                    )->setValue(
                        $label,
                        $id,
                    );

                    return $label;
                },
                $ids,
            ),
        );

        return [
            new OrganPageFlowType(
                new RequestStack(),
                Validation::createValidator(),
                new IdentityTranslator(),
            ),
            new PageStepType($labels),
            new ContactStepType(),
            new ImagesStepType(),
            new SocialLinksType(),
            new LocalisedTextType(),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function flow(
        array $options = [],
        ?OrganPageData $data = null,
    ): FormFlowInterface {
        $flow = $this->factory->create(
            OrganPageFlowType::class,
            $data ?? new OrganPageData(),
            $options,
        );
        assert($flow instanceof FormFlowInterface);

        return $flow;
    }

    private function revisionWithLabels(): OrganInformationRevision
    {
        $label = new OrganLabel();
        $label->name = new DecisionLocalisedText(
            'Fun',
            'Fun',
        );
        new ReflectionProperty(
            OrganLabel::class,
            'id',
        )->setValue(
            $label,
            7,
        );

        $revision = new OrganInformationRevision();
        $revision->addLabel($label);

        return $revision;
    }
}
