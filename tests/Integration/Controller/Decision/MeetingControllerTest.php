<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\MeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocumentVersion;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\ReferenceDocumentVersion;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Decision\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function sprintf;

/**
 * The member-facing meeting pages: the two entry pages, one meeting, and the four downloads. The downloads serve the
 * stored bytes, so the file name a member sees is what these pin, not the storage path.
 */
final class MeetingControllerTest extends DatabaseTestCase
{
    public function testTheMeetingOverviewRenders(): void
    {
        $this->authenticateMember();
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Search decisions',
            (string) $response->getContent(),
        );
    }

    public function testTheDecisionSearchRenders(): void
    {
        $this->authenticateMember();
        $this->pushRequest();

        $response = $this->controller()->search();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Search decisions',
            (string) $response->getContent(),
        );
    }

    /**
     * The address uses the member-facing token (`gmm`), while the page names the meeting the way a decision does.
     */
    public function testAMeetingIsShownUnderItsOrdinal(): void
    {
        $this->authenticateMember();
        $request = $this->pushRequest();
        $meeting = $this->meeting();

        $response = $this->controller()->view(
            $request,
            'gmm',
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            sprintf(
                '%d',
                $meeting->number,
            ),
            $content,
        );
        self::assertStringContainsString(
            'documents.zip',
            $content,
        );
    }

    public function testAMeetingThatDoesNotExistIsNotFound(): void
    {
        $this->authenticateMember();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->view(
            new Request(),
            'gmm',
            99999,
        );
    }

    public function testTheDocumentsOfAMeetingAreZippedForDownload(): void
    {
        $this->authenticateMember();
        $meeting = $this->meeting();
        $this->mirrorDocumentsOf($meeting);

        $response = $this->controller()->downloadAllDocuments(
            'gmm',
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d documents.zip',
                $meeting->number,
            ),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testTheDocumentsOfAMeetingThatDoesNotExistAreNotFound(): void
    {
        $this->authenticateMember();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->downloadAllDocuments(
            'gmm',
            99999,
        );
    }

    /**
     * A meeting without any document has nothing to put in an archive, which is what the second lookup refuses.
     */
    public function testAMeetingWithoutDocumentsHasNoArchive(): void
    {
        $this->authenticateMember();

        // Board meetings have no documents in the seed, and the archive has nothing to put in a file.
        $empty = self::getContainer()->get(MeetingRepository::class)->findByType(MeetingTypes::BV)[0];

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->downloadAllDocuments(
            'bm',
            $empty->number,
        );
    }

    public function testADocumentIsDownloadedUnderItsName(): void
    {
        $this->authenticateMember();

        $repository = $this->entityManager->getRepository(MeetingDocumentVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            MeetingDocumentVersion::class,
            $version,
            'The seed is expected to contain a meeting document.',
        );

        $this->mirror($version->path);

        $response = $this->controller()->downloadDocument($version);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'application/pdf',
            $response->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            sprintf(
                '%s (%s).pdf',
                $version->document->name,
                $version->versionLabel,
            ),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testTheMinutesAreDownloadedUnderTheMeetingAndVersion(): void
    {
        $this->authenticateMember();

        $repository = $this->entityManager->getRepository(MeetingMinutesVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            MeetingMinutesVersion::class,
            $version,
            'The seed is expected to contain meeting minutes.',
        );

        $this->mirror($version->path);

        $response = $this->controller()->downloadMinutes($version);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '%s %d minutes (%s).pdf',
                $version->minutes->meeting->type->value,
                $version->minutes->meeting->number,
                $version->versionLabel,
            ),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testAReferenceDocumentIsDownloadedUnderItsName(): void
    {
        $this->authenticateMember();

        $repository = $this->entityManager->getRepository(ReferenceDocumentVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            ReferenceDocumentVersion::class,
            $version,
            'The seed is expected to contain a reference document.',
        );

        $this->mirror($version->path);

        $response = $this->controller()->downloadReference($version);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                '%s (%s).pdf',
                $version->referenceDocument->name,
                $version->versionLabel,
            ),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    private function controller(): MeetingController
    {
        return self::getContainer()->get(MeetingController::class);
    }

    /**
     * The complete GMM: the one past meeting the seed hangs documents, minutes, and reference selections on.
     */
    private function meeting(): Meeting
    {
        $meeting = self::getContainer()->get(MeetingRepository::class)->findMeeting(
            MeetingTypes::ALV,
            $this->documentedMeetingNumber(),
        );
        self::assertNotNull(
            $meeting,
            'The seed is expected to contain the complete GMM.',
        );

        return $meeting;
    }

    private function documentedMeetingNumber(): int
    {
        $repository = $this->entityManager->getRepository(MeetingMinutesVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            MeetingMinutesVersion::class,
            $version,
            'The seed is expected to contain meeting minutes.',
        );

        return $version->minutes->meeting->number;
    }

    private function pushRequest(): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    /**
     * The fixture writes the stored bytes to an in-memory adapter in the fixture process, so the rows point at files
     * this process has to have too.
     */
    private function mirror(string $path): void
    {
        $storage = self::getContainer()->get('default.storage');
        self::assertInstanceOf(
            FilesystemOperator::class,
            $storage,
        );

        if ($storage->fileExists($path)) {
            return;
        }

        $storage->write(
            $path,
            '%PDF-1.4',
        );
    }

    private function mirrorDocumentsOf(Meeting $meeting): void
    {
        foreach ($meeting->getDocuments() as $document) {
            foreach ($document->getVersions() as $version) {
                $this->mirror($version->path);
            }
        }
    }

    private function authenticateMember(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for the member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Member->value],
            ),
        );
    }
}
