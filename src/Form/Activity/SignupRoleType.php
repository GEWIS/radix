<?php

declare(strict_types=1);

namespace App\Form\Activity;

use App\Entity\Activity\SignupRole;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

use function strval;
use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<SignupRole>
 */
class SignupRoleType extends AbstractType
{
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder
            ->add(
                'name',
                TextType::class,
                [
                    'label' => t('Role'),
                    'constraints' => [new NotBlank(message: 'Name the role.')],
                ],
            )
            ->add(
                'minimum',
                IntegerType::class,
                [
                    'label' => t('Seats guaranteed'),
                    'constraints' => [new Positive(message: 'Guarantee the role at least one seat.')],
                ],
            );

        $builder->add(
            'position',
            HiddenType::class,
            [
                'attr' => ['data-sortable-target' => 'position'],
            ],
        );
        $builder->get('position')->addModelTransformer(new CallbackTransformer(
            static fn (?int $value): string => strval($value ?? 0),
            static fn (?string $value): int => (int) $value,
        ));
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SignupRole::class]);
    }
}
