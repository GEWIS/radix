<?php

declare(strict_types=1);

namespace App\Form\Database\Member;

use App\Entity\Database\Decision;
use App\Form\Database\BaseDecisionType;
use App\Form\Database\DataMapper\Member\WarningMapper;
use App\Form\Database\MemberLookupType;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<Decision>
 */
class WarningType extends AbstractType
{
    public function __construct(private readonly WarningMapper $dataMapper)
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
        $builder
            ->add(
                'member',
                MemberLookupType::class,
            )
            ->add(
                'submit',
                SubmitType::class,
                ['label' => t('Warn Member')],
            )
            ->setDataMapper($this->dataMapper);
    }

    #[Override]
    public function getParent(): string
    {
        return BaseDecisionType::class;
    }
}
