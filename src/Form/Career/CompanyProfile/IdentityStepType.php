<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * How a company is identified and whether it appears at all, which only the board decides.
 *
 * @extends AbstractType<CompanyProfileData>
 */
class IdentityStepType extends AbstractType
{
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
                'name',
                TextType::class,
                ['label' => t('Name')],
            )
            ->add(
                'slugName',
                TextType::class,
                [
                    'label' => t('Slug'),
                    'help' => t('Identifies the company in its web address.'),
                ],
            )
            ->add(
                'published',
                CheckboxType::class,
                [
                    'label' => t('Show this company on the website'),
                    'required' => false,
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
