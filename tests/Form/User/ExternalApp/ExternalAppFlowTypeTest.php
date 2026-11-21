<?php

declare(strict_types=1);

namespace App\Tests\Form\User\ExternalApp;

use App\Form\User\ExternalApp\ApplicationStepType;
use App\Form\User\ExternalApp\ClaimsStepType;
use App\Form\User\ExternalApp\ExternalAppData;
use App\Form\User\ExternalApp\ExternalAppFlowType;
use App\Form\User\ExternalApp\SigningStepType;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;

use function assert;

#[CoversClass(ExternalAppFlowType::class)]
// TypeTestCase creates an unconfigured EventDispatcher mock internally; opt out of the no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class ExternalAppFlowTypeTest extends TypeTestCase
{
    public function testAnApplicationIsRegisteredInThreeSteps(): void
    {
        $flow = $this->flow();

        self::assertSame(
            [
                ExternalAppData::STEP_APPLICATION,
                ExternalAppData::STEP_SIGNING,
                ExternalAppData::STEP_CLAIMS,
            ],
            $flow->getCursor()->getSteps(),
        );
        self::assertInstanceOf(
            ExternalAppData::class,
            $flow->getData(),
        );
    }

    public function testTheFirstStepCollectsHowToReachTheApplication(): void
    {
        $step = $this->factory->create(
            ApplicationStepType::class,
            new ExternalAppData(),
        );

        foreach (
            [
                'appId',
                'callback',
                'url',
            ] as $field
        ) {
            self::assertTrue(
                $step->has($field),
                $field,
            );
        }
    }

    /**
     * Signing cannot be left undecided: a token without an algorithm and a delivery method cannot be handed to the
     * application.
     */
    public function testTheSecondStepCollectsTheSigningAndDeliveryMethod(): void
    {
        $step = $this->factory->create(
            SigningStepType::class,
            new ExternalAppData(),
        );

        foreach (
            [
                'signature',
                'tokenDelivery',
                'secret',
            ] as $field
        ) {
            self::assertTrue(
                $step->has($field),
                $field,
            );
        }

        self::assertTrue($step->get('signature')->getConfig()->getRequired());
        self::assertTrue($step->get('tokenDelivery')->getConfig()->getRequired());
    }

    /**
     * The claims are a set of checkboxes rather than a picker, because an application either receives a fact about the
     * member or it does not, and none of them is mandatory.
     */
    public function testTheLastStepCollectsClaimsAvailabilityAndExpiry(): void
    {
        $step = $this->factory->create(
            ClaimsStepType::class,
            new ExternalAppData(),
        );

        self::assertTrue($step->get('claims')->getConfig()->getOption('multiple'));
        self::assertTrue($step->get('claims')->getConfig()->getOption('expanded'));
        self::assertFalse($step->get('claims')->getConfig()->getRequired());
        self::assertTrue($step->has('enabled'));
        self::assertTrue($step->has('expiresAt'));

        $expiry = $step->get('expiresAt');
        self::assertSame(
            'single_text',
            $expiry->getConfig()->getOption('widget'),
        );
        self::assertFalse($expiry->getConfig()->getRequired());
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
     * @return list<ExternalAppFlowType|ApplicationStepType|SigningStepType|ClaimsStepType>
     */
    #[Override]
    protected function getTypes(): array
    {
        return [
            new ExternalAppFlowType(
                new RequestStack(),
                Validation::createValidator(),
                new IdentityTranslator(),
            ),
            new ApplicationStepType(),
            new SigningStepType(),
            new ClaimsStepType(),
        ];
    }

    private function flow(): FormFlowInterface
    {
        $flow = $this->factory->create(
            ExternalAppFlowType::class,
            new ExternalAppData(),
        );
        assert($flow instanceof FormFlowInterface);

        return $flow;
    }
}
