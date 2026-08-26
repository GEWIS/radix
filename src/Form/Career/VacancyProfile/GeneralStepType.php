<?php

declare(strict_types=1);

namespace App\Form\Career\VacancyProfile;

use App\Entity\Application\Enums\Languages;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyJobPackage;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\VacancyLabel;
use App\Repository\Career\CompanyJobPackageRepository;
use App\Repository\Career\VacancyLabelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function assert;
use function Symfony\Component\Translation\t;

/**
 * The slug and the package sit on the vacancy rather than on a revision, so a change to either takes effect the
 * moment it is saved instead of when the committee agrees to it. Once the vacancy is live `identity_editable` is
 * false and only the board may still change them.
 *
 * @extends AbstractType<VacancyData>
 */
class GeneralStepType extends AbstractType
{
    public function __construct(
        private readonly CompanyJobPackageRepository $packageRepository,
        private readonly VacancyLabelRepository $vacancyLabelRepository,
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
        if (true === $options['identity_editable']) {
            $builder
                ->add(
                    'slugName',
                    TextType::class,
                    [
                        'label' => t('Slug'),
                        'help' => t('Identifies the vacancy in its web address, within its company and category.'),
                    ],
                )
                ->add(
                    'packageId',
                    ChoiceType::class,
                    [
                        'label' => t('Job package'),
                        'placeholder' => false,
                        'choices' => $this->packageChoices(
                            $options['company'],
                            $options['current_package_id'],
                        ),
                    ],
                );
        }

        if (true === $options['admin']) {
            $builder->add(
                'published',
                CheckboxType::class,
                [
                    'label' => t('Show this vacancy on the website'),
                    'required' => false,
                ],
            );
        }

        $builder
            ->add(
                'category',
                EnumType::class,
                [
                    'label' => t('Category'),
                    'class' => VacancyCategories::class,
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
                'startDate',
                DateType::class,
                [
                    'label' => t('Opens on'),
                    'help' => t('Leave empty to show the vacancy as soon as it is approved.'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ],
            )
            ->add(
                'endDate',
                DateType::class,
                [
                    'label' => t('Closes on'),
                    'help' => t('The last day the vacancy is shown.'),
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                ],
            );
    }

    /**
     * A package that has already run out is not on offer any more, but the one a vacancy was sold under has to stay
     * choosable: without it the select comes up empty on edit and the only way to save is to move the vacancy to
     * somebody else's contract.
     *
     * @return array<string, int>
     */
    private function packageChoices(
        ?Company $company,
        ?int $currentPackageId,
    ): array {
        $qb = $this->packageRepository->createQueryBuilder('p');

        if (null === $currentPackageId) {
            $qb->andWhere('p.expires > CURRENT_DATE()');
        } else {
            $qb->andWhere($qb->expr()->orX(
                'p.expires > CURRENT_DATE()',
                'p.id = :current',
            ))
                ->setParameter(
                    'current',
                    $currentPackageId,
                    Types::INTEGER,
                );
        }

        if (null !== $company) {
            $qb->andWhere('p.company = :company')
                ->setParameter(
                    'company',
                    $company->getId(),
                );
        }

        $choices = [];

        foreach ($this->applyOrder($qb)->getQuery()->getResult() as $package) {
            assert($package instanceof CompanyJobPackage);
            $label = $package->getCompany()->getName()
                . ' (' . $package->getExpirationDate()->format('Y-m-d') . ')';
            $choices[$label] = (int) $package->getId();
        }

        return $choices;
    }

    private function applyOrder(QueryBuilder $qb): QueryBuilder
    {
        return $qb->orderBy(
            'p.expires',
            'ASC',
        );
    }

    /**
     * @return array<string, int>
     */
    private function labelChoices(): array
    {
        $language = Languages::current();
        $choices = [];

        foreach ($this->vacancyLabelRepository->findAll() as $label) {
            assert($label instanceof VacancyLabel);
            $choices[$label->getName()->getText($language) ?? ''] = (int) $label->getId();
        }

        return $choices;
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'admin' => false,
            'identity_editable' => true,
            'company' => null,
            'current_package_id' => null,
        ]);

        $resolver->setAllowedTypes(
            'admin',
            'bool',
        );
        $resolver->setAllowedTypes(
            'identity_editable',
            'bool',
        );
        $resolver->setAllowedTypes(
            'company',
            [
                Company::class,
                'null',
            ],
        );
        $resolver->setAllowedTypes(
            'current_package_id',
            [
                'int',
                'null',
            ],
        );
    }
}
