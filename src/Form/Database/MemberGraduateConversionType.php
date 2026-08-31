<?php

declare(strict_types=1);

namespace App\Form\Database;

use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\Member;
use App\Form\DataTransformer\LowercaseTransformer;
use App\Form\DataTransformer\OptInTransformer;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\Translation\t;

/**
 * Which button was pressed is the whole answer.
 *
 * @extends AbstractType<Member>
 */
class MemberGraduateConversionType extends AbstractType
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
            'lastName',
            TextType::class,
            self::fixedOptions(t('Last Name')),
        );
        $builder->add(
            'middleName',
            TextType::class,
            self::fixedOptions(t('Last Name Prepositional Particle')),
        );
        $builder->add(
            'initials',
            TextType::class,
            self::fixedOptions(t('Initial(s)')),
        );
        $builder->add(
            'firstName',
            TextType::class,
            self::fixedOptions(t('First Name')),
        );

        $builder->add(
            'email',
            EmailType::class,
            [
                'label' => t('Email Address'),
                'help' => t('Use an address you keep after you stop studying.'),
                'constraints' => [new Assert\NotBlank()],
            ],
        );

        $builder->add(
            'supremum',
            CheckboxType::class,
            [
                'label' => t('I\'d like to receive the Supremum magazine 3 times a year'),
                'required' => false,
            ],
        );

        $builder->add(
            'accept',
            SubmitType::class,
            ['label' => t('Stay on as a graduate')],
        );

        $builder->add(
            'decline',
            SubmitType::class,
            ['label' => t('End my membership')],
        );

        // The secretary acts on this: the register is not something a member deletes themselves.
        $builder->add(
            'removal',
            CheckboxType::class,
            [
                'label' => t('Also ask the secretary to remove my data'),
                'mapped' => false,
                'required' => false,
            ],
        );

        $builder->get('email')->addModelTransformer(new LowercaseTransformer());
        $builder->get('supremum')->addModelTransformer(new OptInTransformer());
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Member::class]);

        $resolver->setRequired('conversion_link');
        $resolver->setAllowedTypes(
            'conversion_link',
            GraduateConversionLink::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixedOptions(TranslatableMessage $label): array
    {
        return [
            'label' => $label,
            'disabled' => true,
            'attr' => ['readonly' => true],
        ];
    }
}
