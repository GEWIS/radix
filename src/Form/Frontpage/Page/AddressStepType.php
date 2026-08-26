<?php

declare(strict_types=1);

namespace App\Form\Frontpage\Page;

use App\Entity\User\Enums\UserRoles;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<PageData>
 */
class AddressStepType extends AbstractType
{
    /**
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(
        FormBuilderInterface $builder,
        array $options,
    ): void {
        foreach (
            [
                'categoryNL' => t('category'),
                'subCategoryNL' => t('sub-category'),
                'nameNL' => t('page-name'),
                'categoryEN' => t('category'),
                'subCategoryEN' => t('sub-category'),
                'nameEN' => t('page-name'),
            ] as $field => $label
        ) {
            $builder->add(
                $field,
                TextType::class,
                [
                    'label' => $label,
                    'required' => false,
                ],
            );
        }

        // An application user's page is not reassigned from here, so the field is left out rather than offered with
        // the one role it may not be given.
        if (true !== $options['role_editable']) {
            return;
        }

        $builder->add(
            'requiredRole',
            EnumType::class,
            [
                'label' => t('Who may read this page'),
                'class' => UserRoles::class,
                'choice_filter' => static fn (?UserRoles $role): bool => UserRoles::ApiUser !== $role,
            ],
        );
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'inherit_data' => true,
            'role_editable' => true,
        ]);

        $resolver->setAllowedTypes(
            'role_editable',
            'bool',
        );
    }
}
