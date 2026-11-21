<?php

declare(strict_types=1);

namespace App\Tests\Service\Decision;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\OrganInformationRevision;
use App\Repository\Decision\OrganInformationRevisionRepository;
use App\Service\Decision\OrganNotificationSubjectNamer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A page awaiting review is named after the body that wrote it, which is what the board recognises it as; the page
 * itself has no name.
 */
final class OrganNotificationSubjectNamerTest extends TestCase
{
    public function testItOnlyHandlesBodyPageRevisions(): void
    {
        $namer = new OrganNotificationSubjectNamer(self::createStub(OrganInformationRevisionRepository::class));

        self::assertTrue($namer->supports(NotificationType::OrganInformationRevisionAwaitingReview));
        self::assertFalse($namer->supports(NotificationType::CompanyRevisionAwaitingReview));
        self::assertFalse($namer->supports(NotificationType::ActivityAwaitingReview));
    }

    public function testItNamesEachRevisionByTheBodysAbbreviation(): void
    {
        $revision = $this->revision(
            7,
            'BAC',
        );

        $repository = $this->createMock(OrganInformationRevisionRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['id' => [7]])
            ->willReturn([$revision]);

        $names = new OrganNotificationSubjectNamer($repository)->namesFor(
            NotificationType::OrganInformationRevisionAwaitingReview,
            [7],
        );

        self::assertSame(
            [
                7 => [
                    'en' => 'BAC',
                    'nl' => 'BAC',
                ],
            ],
            $names,
        );
    }

    /**
     * A revision that was never persisted has no subject to name, so it must not produce an entry keyed on null.
     */
    public function testARevisionWithoutAnIdentifierIsSkipped(): void
    {
        $repository = self::createStub(OrganInformationRevisionRepository::class);
        $repository->method('findBy')->willReturn([
            $this->revision(
                null,
                'KEUR',
            ),
        ]);

        self::assertSame(
            [],
            new OrganNotificationSubjectNamer($repository)->namesFor(
                NotificationType::OrganInformationRevisionAwaitingReview,
                [1],
            ),
        );
    }

    private function revision(
        ?int $id,
        string $abbr,
    ): OrganInformationRevision {
        $organ = new Organ();
        $organ->abbr = $abbr;

        $information = new OrganInformation();
        $information->organ = $organ;

        $revision = new OrganInformationRevision();
        $revision->organInformation = $information;

        if (null !== $id) {
            new ReflectionProperty(
                OrganInformationRevision::class,
                'id',
            )->setValue(
                $revision,
                $id,
            );
        }

        return $revision;
    }
}
