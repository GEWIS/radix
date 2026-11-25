<?php

declare(strict_types=1);

namespace App\Tests\Form\Database\DataMapper;

use App\Entity\Database\Decision;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision;
use App\Form\Database\DataMapper\AbstractDecisionMapper;
use App\Tests\Support\BuildsDecisions;
use ArrayIterator;
use DateTimeImmutable;
use Symfony\Component\Form\FormInterface;

use function array_values;

/**
 * Runs a decision mapper against the children a decision form would submit. The mappers read the children by
 * name, so form stubs with the submitted values are enough.
 */
trait MapsDecisions
{
    use BuildsDecisions;

    /**
     * @param array<string, mixed> $children
     */
    private function mapDecision(
        AbstractDecisionMapper $mapper,
        array $children,
    ): Decision {
        $decision = new Decision();

        $mapper->mapFormsToData(
            new ArrayIterator($this->children([
                'meeting' => $this->meeting(),
                'point' => 5,
                'decision' => 1,
            ] + $children)),
            $decision,
        );

        return $decision;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, FormInterface<mixed>>
     */
    private function children(array $data): array
    {
        $forms = [];
        foreach ($data as $name => $value) {
            $form = self::createStub(FormInterface::class);
            $form->method('getData')->willReturn($value);
            $forms[$name] = $form;
        }

        return $forms;
    }

    private function member(
        int $lidnr,
        string $firstName = 'Test',
        string $lastName = 'Lid',
    ): Member {
        $member = new Member();
        $member->lidnr = $lidnr;
        $member->initials = 'T.';
        $member->firstName = $firstName;
        $member->middleName = '';
        $member->lastName = $lastName;
        $member->changedOn = new DateTimeImmutable('2026-01-01');

        return $member;
    }

    /**
     * @return list<SubDecision>
     */
    private function subDecisions(Decision $decision): array
    {
        return array_values($decision->getSubdecisions()->toArray());
    }
}
