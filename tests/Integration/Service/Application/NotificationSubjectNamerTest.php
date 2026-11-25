<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Application;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyRevision;
use App\Entity\Career\VacancyRevision;
use App\Repository\Activity\ActivityRepository;
use App\Repository\Career\CompanyBannerPackageRepository;
use App\Service\Activity\ActivityNotificationSubjectNamer;
use App\Service\Career\CareerNotificationSubjectNamer;
use App\Tests\Integration\DatabaseTestCase;

use function array_keys;

/**
 * A notice names the thing it is about in both languages, because the centre renders in whichever language the member
 * reads. An activity is named from itself or from the revision the reviewer is pointed at; a company profile and a
 * proposed banner have no name of their own and are named after their company.
 */
final class NotificationSubjectNamerTest extends DatabaseTestCase
{
    public function testAnActivityNamerSupportsOnlyItsOwnNotifications(): void
    {
        $namer = self::getContainer()->get(ActivityNotificationSubjectNamer::class);

        self::assertTrue($namer->supports(NotificationType::ActivityPublished));
        self::assertTrue($namer->supports(NotificationType::ActivityAwaitingReview));
        self::assertFalse($namer->supports(NotificationType::AlbumPublished));
    }

    public function testAPublishedActivityIsNamedFromTheActivity(): void
    {
        $activity = $this->activity();
        $namer = self::getContainer()->get(ActivityNotificationSubjectNamer::class);

        $names = $namer->namesFor(
            NotificationType::ActivityPublished,
            [(int) $activity->id],
        );

        self::assertSame(
            [(int) $activity->id],
            array_keys($names),
        );
        self::assertSame(
            $this->localised(
                $activity->getName()->getValueEN(),
                $activity->getName()->getValueNL(),
            ),
            $names[(int) $activity->id],
        );
    }

    public function testAnActivityAwaitingReviewIsNamedFromTheRevision(): void
    {
        $revision = $this->entityManager->getRepository(ActivityRevision::class)->findOneBy([]);
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $namer = self::getContainer()->get(ActivityNotificationSubjectNamer::class);

        $names = $namer->namesFor(
            NotificationType::ActivityAwaitingReview,
            [(int) $revision->id],
        );

        self::assertSame(
            $this->localised(
                $revision->name->getValueEN(),
                $revision->name->getValueNL(),
            ),
            $names[(int) $revision->id],
        );
    }

    public function testAnUnknownSubjectContributesNoName(): void
    {
        $namer = self::getContainer()->get(ActivityNotificationSubjectNamer::class);

        self::assertSame(
            [],
            $namer->namesFor(
                NotificationType::ActivityPublished,
                [999999],
            ),
        );
    }

    public function testACareerNamerSupportsOnlyItsOwnNotifications(): void
    {
        $namer = self::getContainer()->get(CareerNotificationSubjectNamer::class);

        self::assertTrue($namer->supports(NotificationType::CompanyRevisionAwaitingReview));
        self::assertTrue($namer->supports(NotificationType::VacancyRevisionAwaitingReview));
        self::assertTrue($namer->supports(NotificationType::CompanyBannerAwaitingReview));
        self::assertFalse($namer->supports(NotificationType::ActivityPublished));
    }

    public function testAVacancyIsNamedFromItsRevision(): void
    {
        $revision = $this->entityManager->getRepository(VacancyRevision::class)->findOneBy([]);
        self::assertInstanceOf(
            VacancyRevision::class,
            $revision,
        );
        $namer = self::getContainer()->get(CareerNotificationSubjectNamer::class);

        $names = $namer->namesFor(
            NotificationType::VacancyRevisionAwaitingReview,
            [(int) $revision->id],
        );

        self::assertSame(
            $this->localised(
                $revision->name->getValueEN(),
                $revision->name->getValueNL(),
            ),
            $names[(int) $revision->id],
        );
    }

    public function testACompanyProfileIsNamedByItsCompany(): void
    {
        $revision = $this->entityManager->getRepository(CompanyRevision::class)->findOneBy([]);
        self::assertInstanceOf(
            CompanyRevision::class,
            $revision,
        );
        $namer = self::getContainer()->get(CareerNotificationSubjectNamer::class);

        $names = $namer->namesFor(
            NotificationType::CompanyRevisionAwaitingReview,
            [(int) $revision->id],
        );

        self::assertSame(
            [
                'en' => $revision->company->name,
                'nl' => $revision->company->name,
            ],
            $names[(int) $revision->id],
        );
    }

    public function testAProposedBannerIsNamedByItsCompany(): void
    {
        $banner = self::getContainer()->get(CompanyBannerPackageRepository::class)->findOneBy([]);
        self::assertInstanceOf(
            CompanyBannerPackage::class,
            $banner,
        );
        $namer = self::getContainer()->get(CareerNotificationSubjectNamer::class);

        $names = $namer->namesFor(
            NotificationType::CompanyBannerAwaitingReview,
            [(int) $banner->id],
        );

        self::assertSame(
            [
                'en' => $banner->company->name,
                'nl' => $banner->company->name,
            ],
            $names[(int) $banner->id],
        );
    }

    /**
     * @return array{en: string, nl: string}
     */
    private function localised(
        ?string $en,
        ?string $nl,
    ): array {
        return [
            'en' => $en ?? '',
            'nl' => $nl ?? '',
        ];
    }

    private function activity(): Activity
    {
        $activity = self::getContainer()->get(ActivityRepository::class)->findOneBy([]);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }
}
