<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\ActivityController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Decision\AssociationYear;
use App\Entity\Photo\Album;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Photo\AlbumRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The public activity pages, invoked directly (the codebase has no WebTestCase). The archive renders the overview
 * component with a required year, and the cross-year search is its own page reusing the same component.
 */
final class ActivityControllerTest extends DatabaseTestCase
{
    public function testIndexRendersTheUpcomingOverview(): void
    {
        $this->pushRequest();

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->index()->getStatusCode(),
        );
    }

    public function testArchiveRendersForTheCurrentAssociationYear(): void
    {
        $this->pushRequest();

        // The current year's archive lists finished activities only (past-only), but the page still renders.
        $year = AssociationYear::fromDate(new DateTimeImmutable())->getYear();

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->archive($year)->getStatusCode(),
        );
    }

    public function testSearchRendersTheCrossYearSearchPage(): void
    {
        $this->pushRequest();

        $response = $this->controller()->search();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'activity-overview-search',
            (string) $response->getContent(),
        );
    }

    public function testViewShowsTheMyFutureLogoForCareerActivities(): void
    {
        $this->pushRequest();

        $response = $this->controller()->view($this->approvedActivityId(true));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'myfuture.tue.nl',
            (string) $response->getContent(),
        );
    }

    public function testViewOmitsTheMyFutureLogoForOtherCategories(): void
    {
        $this->pushRequest();

        self::assertStringNotContainsString(
            'myfuture.tue.nl',
            (string) $this->controller()->view($this->approvedActivityId(false))->getContent(),
        );
    }

    public function testViewObfuscatesTheBoardContactEmail(): void
    {
        $this->pushRequest();

        $career = (string) $this->controller()->view($this->approvedActivityId(true))->getContent();
        $other = (string) $this->controller()->view($this->approvedActivityId(false))->getContent();

        // Career activities use the external-relations board (ceb), others the internal board (cib); neither renders a
        // plain, scrapable address.
        self::assertMatchesRegularExpression(
            '/ceb \[[^\]]+\] gewis\.nl/',
            $career,
        );
        self::assertMatchesRegularExpression(
            '/cib \[[^\]]+\] gewis\.nl/',
            $other,
        );
        self::assertStringNotContainsString(
            '@gewis.nl',
            $career,
        );
        self::assertStringNotContainsString(
            '@gewis.nl',
            $other,
        );
    }

    /**
     * A link to an activity shared on social media gets a card with the activity's own text rather than the site's.
     */
    public function testViewDescribesTheActivityForSharing(): void
    {
        $this->pushRequest();

        $content = (string) $this->controller()->view($this->approvedActivityId(false))->getContent();

        self::assertMatchesRegularExpression(
            '/<meta property="og:title" content="[^"]+">/',
            $content,
        );
        self::assertStringNotContainsString(
            'GEWIS is the study association',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/<meta property="og:description" content="[^"]+">/',
            $content,
        );
        self::assertMatchesRegularExpression(
            '/<meta property="og:image" content="http[^"]+">/',
            $content,
        );
    }

    public function testViewListsTheLinkedAlbumsForAMember(): void
    {
        $this->authenticate(
            8030,
            UserRoles::Member,
        );
        $this->pushRequest();
        $album = $this->linkedAlbum();

        $response = $this->controller()->view((int) $album->activity?->id);

        self::assertStringContainsString(
            $this->albumUrl($album),
            (string) $response->getContent(),
        );
    }

    public function testViewAsksAVisitorToSignInForThePhotos(): void
    {
        $this->pushRequest();
        $album = $this->linkedAlbum();

        $content = (string) $this->controller()->view((int) $album->activity?->id)->getContent();

        self::assertStringContainsString(
            'Sign in to see the photos of this activity.',
            $content,
        );
        self::assertStringNotContainsString(
            $this->albumUrl($album),
            $content,
        );
    }

    private function albumUrl(Album $album): string
    {
        return self::getContainer()->get('router')->generate(
            'photo/album',
            [
                'type' => 'album',
                'album' => $album->id,
            ],
        );
    }

    private function linkedAlbum(): Album
    {
        $album = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Movie Night']);
        self::assertInstanceOf(
            Album::class,
            $album,
            'The seed is expected to contain the album linked to an activity.',
        );

        return $album;
    }

    private function authenticate(
        int $lidnr,
        UserRoles $role,
    ): void {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for the member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [$role->value],
            ),
        );
    }

    private function controller(): ActivityController
    {
        return self::getContainer()->get(ActivityController::class);
    }

    private function approvedActivityId(bool $career): int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'lr',
            )
            ->andWhere('a.unpublishedAt IS NULL')
            ->setParameter(
                'career',
                ActivityCategories::Career->value,
            )
            ->setMaxResults(1);

        $queryBuilder->andWhere(
            $career
                ? 'lr.category = :career'
                : 'lr.category != :career',
        );

        $activity = $queryBuilder->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return (int) $activity->id;
    }

    private function pushRequest(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
