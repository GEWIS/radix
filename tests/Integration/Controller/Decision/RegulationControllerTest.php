<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\RegulationController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Who may download which regulation, and from where. The board keeps the current version of a policy in that policy's
 * own folder as `<slug>-latest.pdf`; only the two policies an activity sign-up makes somebody agree to are served to a
 * visitor without an account, and every other regulation stays behind the login as it was.
 *
 * Invoked directly, which is this codebase's pattern for controller tests; the access check being exercised lives in
 * the action itself rather than on the class, precisely so these two can be let through.
 */
final class RegulationControllerTest extends DatabaseTestCase
{
    public function testTheActivityPolicyIsDownloadedWithoutAnAccount(): void
    {
        $this->mirror(
            'activity-policy',
            'Activity Policy',
        );

        $response = $this->controller()->download('activity-policy');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Activity Policy.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testTheAlcoholPolicyIsDownloadedWithoutAnAccount(): void
    {
        $this->mirror(
            'alcohol-policy',
            'Alcohol Policy',
        );

        $response = $this->controller()->download('alcohol-policy');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    /**
     * The file is mirrored and readable, so the only thing that can refuse the download is the access check.
     */
    public function testEveryOtherRegulationIsRefusedWithoutAnAccount(): void
    {
        $this->mirror(
            'house-rules',
            'House rules',
        );

        $this->expectException(AccessDeniedException::class);

        $this->controller()->download('house-rules');
    }

    public function testAMemberDownloadsTheRegulationsThatAreNotPublic(): void
    {
        $this->mirror(
            'house-rules',
            'House rules',
        );
        $this->authenticateMember();

        $response = $this->controller()->download('house-rules');

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnUnknownRegulationIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->download('the-inner-circle');
    }

    public function testAPolicyThatHasNotBeenMirroredYetIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->download('activity-policy');
    }

    private function mirror(
        string $slug,
        string $folder,
    ): void {
        $storage = self::getContainer()->get('default.storage');
        self::assertInstanceOf(
            FilesystemOperator::class,
            $storage,
        );

        $storage->write(
            'public-archive/Policies & Regulations/' . $folder . '/' . $slug . '-latest.pdf',
            '%PDF-1.7',
        );
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

    private function controller(): RegulationController
    {
        return self::getContainer()->get(RegulationController::class);
    }
}
