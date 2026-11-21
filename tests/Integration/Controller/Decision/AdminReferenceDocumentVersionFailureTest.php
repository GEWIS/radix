<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AdminReferenceDocumentController;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\Decision\ReferenceDocumentVersion;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function file_put_contents;
use function is_file;
use function json_decode;
use function register_shutdown_function;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A new library version that storage refuses is answered with the reason, exactly as a refused first upload is.
 */
final class AdminReferenceDocumentVersionFailureTest extends DatabaseTestCase
{
    public function testAVersionThatCannotBeStoredIsReported(): void
    {
        $this->authenticateBoard();

        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy([
            'name' => 'Scenarios and Procedures',
        ]);
        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
        );

        $request = Request::create(
            '/',
            'POST',
            ['versionLabel' => 'v-refused'],
        );
        $request->files->set(
            'file',
            $this->aTextFile(),
        );

        $response = $this->controller()->uploadVersion(
            $request,
            $document,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );

        $json = json_decode(
            (string) $response->getContent(),
            true,
        );
        self::assertIsArray($json);
        self::assertStringContainsString(
            'MIME type',
            $json['error'],
        );
        $this->entityManager->clear();
        $versions = $this->entityManager->getRepository(ReferenceDocumentVersion::class);
        self::assertNull(
            $versions->findOneBy([
                'versionLabel' => 'v-refused',
            ]),
            'A refused version is not filed.',
        );
    }

    private function controller(): AdminReferenceDocumentController
    {
        return self::getContainer()->get(AdminReferenceDocumentController::class);
    }

    private function aTextFile(): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'it-reference-version-',
        );
        self::assertNotFalse($path);
        file_put_contents(
            $path,
            'just some text',
        );

        $file = new UploadedFile(
            $path,
            'notes.txt',
            'text/plain',
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

    private function authenticateBoard(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
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
