<?php

declare(strict_types=1);

namespace App\Form\Database;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

use function Symfony\Component\Translation\t;

/**
 * The English text alone. The Dutch text is the record and is never written here.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class OtherTranslationType extends AbstractType
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
                'contentEN',
                TextType::class,
                [
                    'label' => t('Decision'),
                    'constraints' => [
                        new NotBlank(),
                        new Length(min: 3),
                    ],
                ],
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => t('Save Translation')],
            );
    }
}
