<?php

declare(strict_types=1);

namespace App\Form\User\ExternalApp;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<ExternalAppData>
 */
final class ApplicationStepType extends AbstractType
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
                'appId',
                TextType::class,
                [
                    'label' => t('Application identifier'),
                    'help' => t('Used in the authentication URL (/user/token/{identifier}).'),
                ],
            )
            ->add(
                'callback',
                UrlType::class,
                [
                    'label' => t('Callback URL'),
                    'help' => t('Where the member is sent with the token after authenticating.'),
                ],
            )
            ->add(
                'url',
                UrlType::class,
                [
                    'label' => t('Application URL'),
                    'help' => t('Where the member is sent when they decline.'),
                ],
            );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['inherit_data' => true]);
    }
}
