<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Photo;

use App\Controller\Photo\AdminController;
use App\Entity\Activity\Activity;
use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Decision\AssociationYear;
use App\Entity\Photo\Album;
use App\Entity\Photo\Photo;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Service\Application\FileStorage;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The photo admin controller, invoked directly (the codebase has no WebTestCase). It covers the year-filtered overview,
 * the JSON upload endpoint, and the bulk move and delete of a photo selection. The class-level board guard and the CSRF
 * attributes are enforced by the framework at the HTTP layer, so a direct call exercises the action body itself.
 */
final class PhotoAdminControllerTest extends DatabaseTestCase
{
    public function testIndexRendersTheOverviewFilteredByYear(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $trip = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Trip 2024']);
        self::assertInstanceOf(
            Album::class,
            $trip,
        );
        $start = $trip->startDateTime;
        self::assertNotNull($start);

        $response = $this->controller()->index(AssociationYear::fromDate($start)->getYear());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Trip 2024',
            (string) $response->getContent(),
        );
    }

    public function testWeeklyAdminPageRenders(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->weekly();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testUploadStoresPhotosAndReturnsASummary(): void
    {
        $this->authenticateBoard();
        $album = $this->album('Trip 2024');

        $request = new Request();
        $request->files->set(
            'photos',
            [$this->imageUpload()],
        );

        $response = $this->controller()->upload(
            $album,
            $request,
        );

        $summary = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            [
                'created' => 1,
                'duplicates' => 0,
                'failed' => 0,
            ],
            $summary,
        );
    }

    public function testMovePhotosReassignsTheSelection(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $trip = $this->album('Trip 2024');
        $gala = $this->album('Gala 2024');
        $photo = $this->storedPhoto($trip);

        $request = new Request();
        $request->request->set(
            'photos',
            [(string) $photo->id],
        );
        $request->request->set(
            'destination',
            (string) $gala->id,
        );

        $response = $this->controller()->movePhotos(
            $trip,
            $request,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $gala->id,
            $photo->album->id,
        );
    }

    public function testEditAlbumLinksTheActivity(): void
    {
        $this->authenticateBoard();
        $trip = $this->album('Trip 2024');
        $activity = $this->album('Movie Night')->activity;
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        $request = Request::create(
            '/en/admin/photos/albums/' . $trip->id . '/edit',
            'POST',
            [
                'album' => [
                    '_csrf_token' => 'csrf-token',
                    'name' => 'Trip 2024',
                    'activity' => (string) $activity->id,
                    'published' => '1',
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $this->pushRequest($request);

        $response = $this->controller()->editAlbum(
            $trip,
            $request,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $activity,
            $trip->activity,
        );
    }

    public function testDeletePhotosRemovesTheSelection(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $trip = $this->album('Trip 2024');
        $photo = $this->storedPhoto($trip);
        $id = (int) $photo->id;

        $request = new Request();
        $request->request->set(
            'photos',
            [(string) $id],
        );

        $response = $this->controller()->deletePhotos(
            $trip,
            $request,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNull($this->photoRepository()->find($id));
    }

    public function testTheCreateFormIsRenderedForARootAlbum(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->createAlbum(new Request());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    /**
     * A sub-album is created from its parent's manage view, so the parent comes in as a query parameter rather than
     * from the form.
     */
    public function testASubAlbumIsCreatedUnderTheGivenParent(): void
    {
        $this->authenticateBoard();
        $parent = $this->album('Trip 2024');

        $request = $this->albumPost([
            'name' => 'Trip 2024 - Day 1',
            'published' => '1',
        ]);
        $this->pushRequest($request);

        $response = $this->controller()->createAlbum(
            $request,
            (int) $parent->id,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        $this->entityManager->clear();
        $created = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Trip 2024 - Day 1']);
        self::assertInstanceOf(
            Album::class,
            $created,
        );
        self::assertSame(
            (int) $parent->id,
            (int) $created->getParent()?->id,
        );
    }

    public function testCreatingAPublishedAlbumAnnouncesIt(): void
    {
        $this->authenticateBoard();
        $request = $this->albumPost([
            'name' => 'Winter Gala',
            'published' => '1',
        ]);
        $this->pushRequest($request);

        $response = $this->controller()->createAlbum($request);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        $this->entityManager->clear();
        $created = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => 'Winter Gala']);
        self::assertInstanceOf(
            Album::class,
            $created,
        );
        self::assertTrue($created->published);
    }

    public function testEditingAnAlbumRendersTheFormWhenNothingWasSubmitted(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();

        $response = $this->controller()->editAlbum(
            $this->album('Trip 2024'),
            new Request(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testDeletingAnAlbumReturnsToTheOverview(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $album = $this->album('Movie Night');
        $id = (int) $album->id;

        $response = $this->controller()->deleteAlbum($album);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNull(self::getContainer()->get(AlbumRepository::class)->find($id));
    }

    public function testTheManagePageAndItsManifestAreServedForADraftAlbum(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $draft = self::getContainer()->get(AlbumRepository::class)->findOneBy(['published' => false]);
        self::assertInstanceOf(
            Album::class,
            $draft,
            'The seed is expected to contain an unpublished album.',
        );

        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->album($draft)->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_OK,
            $this->controller()->albumManifest($draft)->getStatusCode(),
        );
    }

    /**
     * The manage view regenerates in-page and only falls back to a redirect when the request did not come from the
     * script.
     */
    public function testRegeneratingACoverAnswersJsonToTheScriptAndARedirectOtherwise(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $album = $this->album('Trip 2024');

        $scripted = new Request();
        $scripted->headers->set(
            'X-Requested-With',
            'XMLHttpRequest',
        );
        $response = $this->controller()->regenerateCover(
            $album,
            $scripted,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            ['status' => 'queued'],
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $this->controller()->regenerateCover(
                $album,
                new Request(),
            ),
        );
    }

    public function testMovingWithoutADestinationReportsThatNothingWasChosen(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $session = self::getContainer()->get('request_stack')->getSession();

        $request = new Request();
        $request->request->set(
            'photos',
            ['1'],
        );

        $response = $this->controller()->movePhotos(
            $this->album('Trip 2024'),
            $request,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty(self::flashBag($session)->peek('warning'));
    }

    /**
     * A selection that is already in the destination changes nothing, which is said rather than claimed as a move.
     */
    public function testMovingPhotosThatAreAlreadyThereChangesNothing(): void
    {
        $this->authenticateBoard();
        $this->pushRequest();
        $session = self::getContainer()->get('request_stack')->getSession();
        $album = $this->album('Trip 2024');
        $photo = $this->storedPhoto($album);

        $request = new Request();
        $request->request->set(
            'destination',
            (string) $album->id,
        );
        $request->request->set(
            'photos',
            [(string) $photo->id],
        );

        $response = $this->controller()->movePhotos(
            $album,
            $request,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotEmpty(self::flashBag($session)->peek('warning'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function albumPost(array $fields): Request
    {
        $request = Request::create(
            '/en/admin/photos/albums/create',
            'POST',
            ['album' => $fields + ['_csrf_token' => 'csrf-token']],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    private function photoRepository(): PhotoRepository
    {
        return self::getContainer()->get(PhotoRepository::class);
    }

    private function album(string $name): Album
    {
        $album = self::getContainer()->get(AlbumRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            Album::class,
            $album,
            'The seed is expected to contain the album.',
        );

        return $album;
    }

    private function imageUpload(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'gewisweb-admin-controller-test',
        );
        self::assertIsString($path);
        $image = imagecreatetruecolor(
            44,
            28,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            0x0A,
            0x5B,
            0x2C,
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            44,
            28,
            $colour,
        );
        imagejpeg(
            $image,
            $path,
        );

        return new UploadedFile(
            $path,
            'photo.jpg',
            'image/jpeg',
            null,
            true,
        );
    }

    private function storedPhoto(Album $album): Photo
    {
        $file = tempnam(
            sys_get_temp_dir(),
            'gewisweb-admin-controller-test',
        );
        self::assertIsString($file);
        $image = imagecreatetruecolor(
            36,
            36,
        );
        self::assertNotFalse($image);
        $colour = imagecolorallocate(
            $image,
            0x7C,
            0x1D,
            0x4F,
        );
        self::assertNotFalse($colour);
        imagefilledrectangle(
            $image,
            0,
            0,
            36,
            36,
            $colour,
        );
        imagejpeg(
            $image,
            $file,
        );

        $stored = self::getContainer()->get(FileStorage::class)->store(
            StorageNamespace::PhotoOriginal,
            $file,
            (string) $album->id,
        );
        unlink($file);

        $photo = new Photo();
        $photo->album = $album;
        $photo->path = $stored->path;
        $photo->dateTime = new DateTimeImmutable();
        $photo->aspectRatio = 1.0;
        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return $photo;
    }

    private function pushRequest(?Request $request = null): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request ??= new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function authenticateBoard(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a board member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Board->value],
            ),
        );
    }
}
