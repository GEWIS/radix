<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\PeriodProposalLimit;
use App\Entity\Decision\Organ;
use App\Form\Activity\PeriodProposalLimitType;
use App\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

#[CoversClass(PeriodProposalLimitType::class)]
final class PeriodProposalLimitTypeTest extends DatabaseTestCase
{
    /**
     * A negative limit is invalid: zero is the intended way to stop a body.
     */
    public function testANegativeNumberIsRefused(): void
    {
        $form = $this->submit([
            'organ' => (string) $this->organ()->id,
            'maxProposals' => '-1',
        ]);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('maxProposals')->getErrors(),
        );
    }

    public function testZeroIsAValidLimit(): void
    {
        $form = $this->submit([
            'organ' => (string) $this->organ()->id,
            'maxProposals' => '0',
        ]);

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
    }

    /**
     * The body makes the exception unique, so the picker is disabled once the row exists rather than ignored.
     */
    public function testTheBodyCannotBeChangedOnAnExistingLimit(): void
    {
        $limit = new PeriodProposalLimit();
        $limit->organ = $this->organ();
        $limit->maxProposals = 2;
        new ReflectionProperty(
            PeriodProposalLimit::class,
            'id',
        )->setValue(
            $limit,
            17,
        );

        $form = $this->factory()->create(
            PeriodProposalLimitType::class,
            $limit,
            ['csrf_protection' => false],
        );

        self::assertTrue($form->get('organ')->isDisabled());
    }

    public function testANewLimitLetsTheBoardPickTheBody(): void
    {
        $form = $this->factory()->create(
            PeriodProposalLimitType::class,
            new PeriodProposalLimit(),
            ['csrf_protection' => false],
        );

        self::assertFalse($form->get('organ')->isDisabled());
    }

    /**
     * The picker is required, because a limit without a body applies to nothing.
     */
    public function testTheBodyIsRequired(): void
    {
        $form = $this->submit(['maxProposals' => '1']);

        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('organ')->getErrors(),
        );
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return FormInterface<mixed>
     */
    private function submit(array $values): FormInterface
    {
        $form = $this->factory()->create(
            PeriodProposalLimitType::class,
            new PeriodProposalLimit(),
            ['csrf_protection' => false],
        );
        $form->submit($values);

        return $form;
    }

    private function factory(): FormFactoryInterface
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(
            FormFactoryInterface::class,
            $factory,
        );

        return $factory;
    }

    private function organ(): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy([]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return $organ;
    }
}
