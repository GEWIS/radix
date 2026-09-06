<?php

declare(strict_types=1);

namespace App\Form\Activity\ActivityFlow;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Form\Activity\Enums\SignupListSection;
use App\Form\Application\Flow\AbstractStepperFlowType;
use App\Util\Activity\SignupListRule;
use Override;
use Symfony\Component\Form\Flow\ButtonFlowInterface;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\Flow\Type\FinishFlowType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function sprintf;
use function Symfony\Component\Translation\t;

/**
 * The activity form. Everything is staged with the working revision and only goes live on approval.
 */
class ActivityFlowType extends AbstractStepperFlowType
{
    public static function listStep(
        SignupList $list,
        SignupListSection $section,
    ): string {
        return $section->keyFor($list);
    }

    public static function listGroup(SignupList $list): string
    {
        return sprintf(
            'list:%s',
            $list->getLineageId()->toRfc4122(),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildFormFlow(
        FormFlowBuilderInterface $builder,
        array $options,
    ): void {
        parent::buildFormFlow(
            $builder,
            $options,
        );

        $builder
            ->addStep(
                ActivityData::STEP_GENERAL,
                GeneralStepType::class,
                [
                    'schedule_locked' => $options['schedule_locked'],
                    'company_editable' => $options['company_editable'],
                    'bound_organ_id' => $options['bound_organ_id'],
                ],
            )
            ->addStep(
                ActivityData::STEP_DETAILS,
                DetailsStepType::class,
            );

        if (true !== $options['lists']) {
            // Finishing a run without the lists leaves it standing on them, so the editor that picks it up opens there.
            $builder->add(
                'finish',
                FinishFlowType::class,
                [
                    'label' => $options['finish_label'],
                    'handler' => static function (
                        mixed $data,
                        ButtonFlowInterface $button,
                        FormFlowInterface $flow,
                    ): void {
                        $flow->getConfig()->getStepAccessor()->setStep(
                            $data,
                            ActivityData::STEP_SIGNUP_LISTS,
                        );
                        $flow->getConfig()->getDataStorage()->save($data);
                    },
                ],
            );

            return;
        }

        $builder->addStep(
            ActivityData::STEP_SIGNUP_LISTS,
            SignupListInventoryStepType::class,
            [
                'mapped' => false,
                'data' => $options['revision'],
            ],
        );

        foreach (self::listsOf($options) as $list) {
            foreach (SignupListSection::order() as $section) {
                $builder->addStep(
                    self::listStep(
                        $list,
                        $section,
                    ),
                    SignupListStepType::class,
                    [
                        'mapped' => false,
                        'data' => $list,
                        'section' => $section,
                    ],
                );
            }
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'data_class' => ActivityData::class,
            'finish_label' => t('Save draft'),
            'finish_from' => ActivityData::STEP_SIGNUP_LISTS,
            'schedule_locked' => false,
            'company_editable' => true,
            'bound_organ_id' => null,
            'revision' => null,
            'lists' => true,
        ]);

        $resolver->setDefault(
            'step_labels',
            static function (Options $options): array {
                $labels = [
                    ActivityData::STEP_GENERAL => t('General information'),
                    ActivityData::STEP_DETAILS => t('Details'),
                    ActivityData::STEP_SIGNUP_LISTS => t('Sign-up lists'),
                ];

                foreach (self::listsOf($options) as $list) {
                    foreach (SignupListSection::order() as $section) {
                        $labels[self::listStep(
                            $list,
                            $section,
                        )] = $section;
                    }
                }

                return $labels;
            },
        );

        $resolver->setDefault(
            'step_groups',
            function (Options $options): array {
                $groups = [];
                $position = 0;
                foreach (self::listsOf($options) as $list) {
                    ++$position;
                    foreach (SignupListSection::order() as $section) {
                        $groups[self::listStep(
                            $list,
                            $section,
                        )] = [
                            'under' => ActivityData::STEP_SIGNUP_LISTS,
                            'group' => self::listGroup($list),
                            'label' => SignupListRule::label(
                                $list,
                                $position,
                                $this->translator,
                            ),
                            'number' => $position,
                            // Named in every language the activity is written in, which the form still holds here.
                            'complete' => static fn (mixed $data): bool => $section->isFilledIn(
                                $list,
                                $data instanceof ActivityData ? $data->languages() : [],
                            ),
                        ];
                    }
                }

                return $groups;
            },
        );

        $resolver->setAllowedTypes(
            'schedule_locked',
            'bool',
        );

        $resolver->setAllowedTypes(
            'company_editable',
            'bool',
        );
        $resolver->setAllowedTypes(
            'lists',
            'bool',
        );
        $resolver->setAllowedTypes(
            'bound_organ_id',
            [
                'int',
                'null',
            ],
        );
        $resolver->setAllowedTypes(
            'revision',
            [
                ActivityRevision::class,
                'null',
            ],
        );
    }

    /**
     * The lists the run builds its steps from, which a run without the lists has none of.
     *
     * @param array<string, mixed>|Options<array<string, mixed>> $options
     *
     * @return list<SignupList>
     */
    private static function listsOf(array|Options $options): array
    {
        $revision = $options['revision'];

        if (
            true !== $options['lists']
            || !$revision instanceof ActivityRevision
        ) {
            return [];
        }

        return $revision->getSignupLists()->getValues();
    }
}
