<?php

declare(strict_types=1);

namespace App\Form\Decision\OrganPage;

use App\Form\Application\SocialLinksType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<OrganPageData>
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
                'email',
                EmailType::class,
                [
                    'label' => t('Email address'),
                    'help' => t('Shown to signed-in members only.'),
                    'required' => false,
                ],
            )
            ->add(
                'website',
                UrlType::class,
                [
                    'label' => t('Website'),
                    'required' => false,
                    'default_protocol' => 'https',
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
