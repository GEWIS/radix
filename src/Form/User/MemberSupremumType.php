<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Form\DataTransformer\OptInTransformer;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\Translation\t;

/**
 * A member's own choice about receiving the Supremum magazine.
 *
 * @extends AbstractType<array{supremum: bool, submit?: bool}>
 */
class MemberSupremumType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add(
            'supremum',
            CheckboxType::class,
            [
                'label' => t('I\'d like to receive the Supremum magazine 3 times a year'),
                'required' => false,
                'constraints' => [new Assert\NotNull()],
            ],
        );
        $builder->add(
            'submit',
            SubmitType::class,
            ['label' => t('Save Supremum preference')],
        );

        $builder->get('supremum')->addModelTransformer(new OptInTransformer());
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
