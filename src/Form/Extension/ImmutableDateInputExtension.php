<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Override;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sets the `input` option of every date and time field to `datetime_immutable`.
 *
 * Symfony defaults the option to the mutable DateTime, and every date property in the application is a
 * DateTimeImmutable, so a field without the option fails with a TypeError on submit.
 */
final class ImmutableDateInputExtension extends AbstractTypeExtension
{
    #[Override]
    public static function getExtendedTypes(): iterable
    {
        return [
            DateTimeType::class,
            DateType::class,
            TimeType::class,
        ];
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault(
            'input',
            'datetime_immutable',
        );
    }
}
