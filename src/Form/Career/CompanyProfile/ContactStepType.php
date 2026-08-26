<?php

declare(strict_types=1);

namespace App\Form\Career\CompanyProfile;

use App\Form\Application\SocialLinksType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<CompanyProfileData>
 */
class ContactStepType extends AbstractType
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
                'contactName',
                TextType::class,
                [
                    'label' => t('Contact name'),
                    'required' => false,
                ],
            )
            ->add(
                'contactEmail',
                EmailType::class,
                [
                    'label' => t('Contact email address'),
                    'required' => false,
                ],
            )
            ->add(
                'contactPhone',
                TextType::class,
                [
                    'label' => t('Contact phone number'),
                    'required' => false,
                ],
            )
            ->add(
                'contactAddress',
                TextType::class,
                [
                    'label' => t('Address'),
                    'required' => false,
                ],
            )
            ->add(
                'socialLinks',
                SocialLinksType::class,
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
