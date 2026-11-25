<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminReferenceDocumentController;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\Decision\ReferenceDocumentVersion;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function file_put_contents;
use function is_file;
use function json_decode;
use function register_shutdown_function;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The association-wide reference library the board maintains. The page itself is a live component; the tests cover
 * the XHR uploads behind it, where a refused write is a 422 with the reason the dropzone shows.
 */
final class AdminReferenceDocumentControllerTest extends DatabaseTestCase
{
    public function testTheLibraryPageRenders(): void
    {
        $this->authenticateBoard();
        $this->pushRequestWithSudo();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Reference documents',
            (string) $response->getContent(),
        );
    }

    public function testAnUploadWithoutAFileIsRefused(): void
    {
        $this->authenticateBoard();

        $response = $this->controller()->upload(new Request());

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'No file was uploaded.',
            $this->json($response)['error'],
        );
    }

    public function testAnUploadWithATooShortNameIsRefused(): void
    {
        $this->authenticateBoard();

        $request = Request::create(
            '/',
            'POST',
            ['name' => 'x'],
        );
        $request->files->set(
            'file',
            $this->aFile('%PDF-1.4'),
        );

        $response = $this->controller()->upload($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'between 2 and 128 characters',
            $this->json($response)['error'],
        );
    }

    /**
     * The namespace only accepts PDFs, and the reason it gives is what the uploader is shown.
     */
    public function testAnUploadThatIsNotAPdfIsRefused(): void
    {
        $this->authenticateBoard();

        $request = Request::create(
            '/',
            'POST',
            ['name' => 'Not a policy'],
        );
        $request->files->set(
            'file',
            $this->aFile(
                'just some text',
                'notes.txt',
                'text/plain',
            ),
        );

        $response = $this->controller()->upload($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'MIME type',
            $this->json($response)['error'],
        );
    }

    public function testADocumentIsUploadedIntoTheLibrary(): void
    {
        $this->authenticateBoard();

        $request = Request::create(
            '/',
            'POST',
            ['name' => 'New policy'],
        );
        $request->files->set(
            'file',
            $this->aFile('%PDF-1.4'),
        );

        $response = $this->controller()->upload($request);

        self::assertSame(
            ['ok' => true],
            $this->json($response),
        );

        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy([
            'name' => 'New policy',
        ]);
        self::assertNotNull($document);
        $versions = $this->entityManager->getRepository(ReferenceDocumentVersion::class);
        self::assertNotNull(
            $versions->findOneBy([
                'referenceDocument' => $document,
                'versionLabel' => 'v1.0',
            ]),
            'A first upload is filed as the suggested first version.',
        );
    }

    public function testAVersionUploadWithoutAFileIsRefused(): void
    {
        $this->authenticateBoard();

        $response = $this->controller()->uploadVersion(
            new Request(),
            $this->aDocument(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertSame(
            'No file was uploaded.',
            $this->json($response)['error'],
        );
    }

    public function testANewVersionOfAnExistingDocumentIsUploaded(): void
    {
        $this->authenticateBoard();
        $document = $this->aDocument();

        $request = Request::create(
            '/',
            'POST',
            ['versionLabel' => 'v9.9'],
        );
        $request->files->set(
            'file',
            $this->aFile('%PDF-1.4'),
        );

        $response = $this->controller()->uploadVersion(
            $request,
            $document,
        );

        self::assertSame(
            ['ok' => true],
            $this->json($response),
        );
        $versions = $this->entityManager->getRepository(ReferenceDocumentVersion::class);
        self::assertNotNull(
            $versions->findOneBy([
                'referenceDocument' => $document,
                'versionLabel' => 'v9.9',
            ]),
        );
    }

    private function controller(): AdminReferenceDocumentController
    {
        return self::getContainer()->get(AdminReferenceDocumentController::class);
    }

    private function aDocument(): ReferenceDocument
    {
        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy([
            'name' => 'Scenarios and Procedures',
        ]);
        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
            'The seed is expected to contain the reference library.',
        );

        return $document;
    }

    private function aFile(
        string $contents,
        string $name = 'policy.pdf',
        string $mimeType = 'application/pdf',
    ): UploadedFile {
        $path = tempnam(
            sys_get_temp_dir(),
            'it-reference-',
        );
        self::assertNotFalse($path);
        file_put_contents(
            $path,
            $contents,
        );

        $file = new UploadedFile(
            $path,
            $name,
            $mimeType,
            null,
            true,
        );

        register_shutdown_function(static function () use ($path): void {
            if (!is_file($path)) {
                return;
            }

            unlink($path);
        });

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        return json_decode(
            (string) $response->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
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

    private function pushRequestWithSudo(): Request
    {
        $request = $this->pushRequest();
        $request->cookies->set(
            $request->getSession()->getName(),
            'test',
        );
        self::getContainer()->get(SudoMode::class)->grant();

        return $request;
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
