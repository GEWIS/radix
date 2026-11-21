<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\PeriodProposalLimit;
use App\Entity\Decision\Organ;
use App\Form\Activity\PeriodProposalLimitType;
use App\Repository\Decision\OrganRepository;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

#[CoversClass(PeriodProposalLimitType::class)]
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class PeriodProposalLimitTypeTest extends TypeTestCase
{
    /**
     * A negative limit is invalid: zero is the intended way to stop a body.
     */
    public function testANegativeNumberIsRefused(): void
    {
        $form = $this->submit([
            'organ' => $this->organ(),
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
            'organ' => $this->organ(),
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

        $form = $this->factory->create(
            PeriodProposalLimitType::class,
            $limit,
        );

        self::assertTrue($form->get('organ')->isDisabled());
    }

    public function testANewLimitLetsTheBoardPickTheBody(): void
    {
        $form = $this->factory->create(
            PeriodProposalLimitType::class,
            new PeriodProposalLimit(),
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
     * @return list<FormExtensionInterface>
     */
    #[Override]
    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<PeriodProposalLimitType>
     */
    #[Override]
    protected function getTypes(): array
    {
        $repository = self::createStub(OrganRepository::class);
        $repository->method('findActive')->willReturn([
            $this->organ(
                1,
                'TC',
            ),
            $this->organ(
                2,
                'KCC',
            ),
        ]);

        return [new PeriodProposalLimitType($repository)];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return FormInterface<mixed>
     */
    private function submit(array $values): FormInterface
    {
        $form = $this->factory->create(
            PeriodProposalLimitType::class,
            new PeriodProposalLimit(),
        );
        $form->submit($values);

        return $form;
    }

    /**
     * The form uses a repository in production; the test submits the organ object itself because it has no entity
     * manager.
     */
    private function organ(
        int $id = 1,
        string $abbr = 'TC',
    ): Organ {
        $organ = new Organ();
        $organ->abbr = $abbr;
        new ReflectionProperty(
            Organ::class,
            'id',
        )->setValue(
            $organ,
            $id,
        );

        return $organ;
    }
}
