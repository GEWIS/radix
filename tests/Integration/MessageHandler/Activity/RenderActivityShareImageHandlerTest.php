<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler\Activity;

use App\Controller\Activity\ActivityController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\ImageVariant;
use App\Entity\Application\Enums\Languages;
use App\Message\Activity\RenderActivityShareImageMessage;
use App\MessageHandler\Activity\RenderActivityShareImageHandler;
use App\Service\Application\FilePathResolver;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function getimagesizefromstring;

/**
 * Handling the message leaves an approved activity with a card per language that the image route can serve as PNG,
 * and the activity's page uses that card as the image to share.
 */
final class RenderActivityShareImageHandlerTest extends DatabaseTestCase
{
    public function testDrawsStoresAndServesACardPerLanguage(): void
    {
        $activity = $this->entityManager->getRepository(Activity::class)->findOneBy(['unpublishedAt' => null]);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );
        $revision = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
        );
        $id = $revision->id;
        self::assertNotNull($id);

        $container = self::getContainer();
        $container->get(RenderActivityShareImageHandler::class)(new RenderActivityShareImageMessage($id));

        foreach (Languages::cases() as $language) {
            $path = $activity->getShareImagePath($language);
            self::assertNotNull($path);
            self::assertNotNull($container->get(FilePathResolver::class)->namespaceForPath($path));

            $generator = $container->get(VariantGenerator::class);
            self::assertTrue($generator->variantExists(
                $path,
                ImageVariant::Share,
            ));

            $size = getimagesizefromstring($container->get(FileStorage::class)->read($generator->cachePath(
                $path,
                ImageVariant::Share,
            )));
            self::assertNotFalse($size);
            self::assertSame(
                'image/png',
                $size['mime'],
            );
        }

        // The seed's lists open and close around the activity, so a next moment exists unless every one has passed.
        $next = SignupList::nextTransitionAmong(
            $revision->getSignupLists(),
            new DateTimeImmutable(),
        );
        self::assertEquals(
            $next,
            $activity->shareImageStaleAt,
        );

        $activityId = $activity->id;
        self::assertNotNull($activityId);
        $this->pushRequest();
        $content = (string) self::getContainer()->get(ActivityController::class)->view($activityId)->getContent();

        self::assertMatchesRegularExpression(
            '#<meta property="og:image" content="[^"]*/img/share/activities/share/[0-9a-f]+\.png">#',
            $content,
        );
        self::assertStringContainsString(
            '<meta name="twitter:card" content="summary_large_image">',
            $content,
        );
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
