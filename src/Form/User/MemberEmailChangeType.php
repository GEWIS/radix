<?php

declare(strict_types=1);

namespace App\Form\User;

use App\Form\DataTransformer\LowercaseTransformer;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\Translation\t;

/**
 * Nothing here changes the member: what is submitted is what a confirmation is sent to.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
class MemberEmailChangeType extends AbstractType
{
    public function __construct(private readonly LowercaseTransformer $lowercaseTransformer)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        $builder->add(
            'email',
            EmailType::class,
            [
                'label' => t('New e-mail address'),
                'help' => t('We send a message to this address to confirm that it reaches you.'),
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                    new Assert\Length(max: 255),
                ],
            ],
        );

        $builder->get('email')->addModelTransformer($this->lowercaseTransformer);

        $builder->add(
            'submit',
            SubmitType::class,
            ['label' => t('Change e-mail address')],
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
