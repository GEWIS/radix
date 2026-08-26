<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Application\Enums\Languages;
use App\Entity\Career\Company;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Activity\ActivityLabelRepository;
use App\Repository\Career\CompanyRepository;
use App\Repository\Decision\OrganRepository;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_values;
use function assert;
use function in_array;
use function intval;
use function Symfony\Component\Translation\t;

/**
 * The organ choices are the ones the user may organise for, plus whichever organ the revision already carries: an
 * edit must never silently drop an organ the user cannot otherwise pick, and anything outside that list is refused
 * by the choice itself.
 *
 * @extends AbstractType<ActivityData>
 */
class GeneralStepType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
        private readonly OrganRepository $organRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly ActivityLabelRepository $activityLabelRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'organId',
                ChoiceType::class,
                [
                    'label' => t('Organising organ'),
                    'required' => false,
                    'placeholder' => t('No organising organ'),
                    'choices' => $this->organChoices($options['bound_organ_id']),
                ],
            )
            ->add(
                'companyId',
                ChoiceType::class,
                [
                    'label' => t('Organising company'),
                    'required' => false,
                    'placeholder' => t('No organising company'),
                    'choices' => $this->companyChoices(),
                    // Attaching an organising company is C4/board-only; a disabled field renders read-only and keeps
                    // whatever it already had on submit.
                    'disabled' => !$this->security->isGranted(UserRoles::CompanyAdmin->value),
                ],
            )
            ->add(
                'beginTime',
                DateTimeType::class,
                [
                    'label' => t('Start'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    // Once the activity is live and under way its start is history, so it is shown but not changed.
                    'disabled' => true === $options['schedule_locked'],
                ],
            )
            ->add(
                'endTime',
                DateTimeType::class,
                [
                    'label' => t('End'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                ],
            )
            ->add(
                'category',
                EnumType::class,
                [
                    'label' => t('Category'),
                    'class' => ActivityCategories::class,
                    'choices' => ActivityCategories::selectableCases(),
                ],
            )
            ->add(
                'labelIds',
                ChoiceType::class,
                [
                    'label' => t('Labels'),
                    'choices' => $this->labelChoices(),
                    'multiple' => true,
                    'expanded' => false,
                    'autocomplete' => true,
                    'required' => false,
                ],
            )
            ->add(
                'requireGEFLITST',
                CheckboxType::class,
                [
                    'label' => t('This activity needs a GEFLITST member to take photos'),
                    'help' => t(
                        'When this is checked, GEFLITST will be notified that this activity needs a photographer.',
                    ),
                    'required' => false,
                ],
            )
            ->add(
                'requireZettle',
                CheckboxType::class,
                [
                    'label' => t('This activity needs a Zettle for payments'),
                    'help' => t(
                        'When this is checked, the treasurer will be notified that this activity needs a Zettle.',
                    ),
                    'required' => false,
                ],
            );
    }

    /**
     * Every active organ for the board, otherwise only the organs the user is currently installed in (mirroring
     * {@see \App\Security\Application\RevisionVoter}'s rule), plus the one the revision already carries.
     *
     * @return array<string, int>
     */
    private function organChoices(?int $boundOrganId): array
    {
        $organs = $this->selectableOrgans();
        $ids = [];

        foreach ($organs as $organ) {
            $ids[] = intval($organ->getId());
        }

        if (
            null !== $boundOrganId
            && !in_array(
                $boundOrganId,
                $ids,
                true,
            )
        ) {
            $bound = $this->organRepository->find($boundOrganId);

            if (null !== $bound) {
                $organs[] = $bound;
            }
        }

        $choices = [];

        foreach ($organs as $organ) {
            $choices[$organ->getAbbr()] = intval($organ->getId());
        }

        return $choices;
    }

    /**
     * @return Organ[]
     */
    private function selectableOrgans(): array
    {
        if ($this->security->isGranted(UserRoles::Board->value)) {
            return $this->organRepository->findActive();
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        $organs = [];

        foreach ($user->getMember()->getCurrentOrganInstallations() as $installation) {
            $organ = $installation->getOrgan();
            $organs[intval($organ->getId())] = $organ;
        }

        return array_values($organs);
    }

    /**
     * @return array<string, int>
     */
    private function companyChoices(): array
    {
        $choices = [];

        foreach ($this->companyRepository->findAll() as $company) {
            assert($company instanceof Company);
            $choices[$company->getName()] = intval($company->getId());
        }

        return $choices;
    }

    /**
     * @return array<string, int>
     */
    private function labelChoices(): array
    {
        $language = Languages::current();
        $choices = [];

        foreach ($this->activityLabelRepository->findAllWithName() as $label) {
            assert($label instanceof ActivityLabel);
            $choices[$label->getName()->getText($language) ?? ''] = intval($label->getId());
        }

        return $choices;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'schedule_locked' => false,
            'bound_organ_id' => null,
        ]);

        $resolver->setAllowedTypes(
            'schedule_locked',
            'bool',
        );
        $resolver->setAllowedTypes(
            'bound_organ_id',
            [
                'int',
                'null',
            ],
        );
    }
}
