<?php

declare(strict_types=1);

namespace App\Form\Database\Registration;

use App\Entity\Database\MailingList;
use App\Form\Database\MailingListLabel;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatableInterface;

use function array_combine;
use function array_keys;
use function assert;

/**
 * @extends AbstractType<RegistrationData>
 */
class ListsStepType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        // Names rather than records: what is ticked here sits in the session until the last step.
        $byName = [];

        foreach ($options['mailing_lists'] as $list) {
            assert($list instanceof MailingList);
            $byName[$list->getName()] = $list;
        }

        $names = array_keys($byName);

        $builder->add(
            'lists',
            ChoiceType::class,
            [
                'label' => false,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'choices' => array_combine(
                    $names,
                    $names,
                ),
                // `MailingListLabel` stacks the list name above its description, so the choice labels carry markup.
                'label_html' => true,
                'choice_label' => static function (string $name) use ($byName): TranslatableInterface {
                    return new MailingListLabel($byName[$name]);
                },
            ],
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'mailing_lists' => [],
        ]);

        $resolver->setAllowedTypes(
            'mailing_lists',
            'array',
        );
    }
}
