<?php

declare(strict_types=1);

namespace App\Controller\Decision;

use App\Entity\User\Enums\UserRoles;
use App\Service\Application\FileDownloadHelper;
use App\Service\Application\FileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function basename;
use function in_array;

/**
 * Serves the association's regulations from the SFTP-mirrored public archive by their dashboard slug. The board keeps
 * the current version of a policy in that policy's own folder as `<slug>-latest.pdf`, so the address of a regulation
 * never changes when a new version is approved.
 *
 * Outside {@see MemberController} because not every regulation is members-only: the two policies an external has to
 * agree to before they may sign up for an activity have to be readable by somebody who has no account at all.
 */
class RegulationController extends AbstractController
{
    /**
     * @param array<string, string> $regulations
     * @param list<string>          $publicRegulations
     */
    public function __construct(
        private readonly FileStorage $fileStorage,
        private readonly FileDownloadHelper $fileDownloadHelper,
        #[Autowire('%app.regulations%')]
        private readonly array $regulations,
        #[Autowire('%app.regulations.public%')]
        private readonly array $publicRegulations,
    ) {
    }

    #[Route(
        path: '/regulations/{regulation}',
        name: 'regulations',
        requirements: ['regulation' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function download(string $regulation): Response
    {
        $archivePath = $this->regulations[$regulation] ?? null;

        if (null === $archivePath) {
            throw $this->createNotFoundException();
        }

        if (
            !in_array(
                $regulation,
                $this->publicRegulations,
                true,
            )
        ) {
            $this->denyAccessUnlessGranted(
                UserRoles::User->value,
                message: 'You are not allowed to view this regulation.',
            );
        }

        $storedPath = 'public-archive/' . $archivePath . '/' . $regulation . '-latest.pdf';

        if (!$this->fileStorage->exists($storedPath)) {
            throw $this->createNotFoundException();
        }

        return $this->fileDownloadHelper->download(
            $storedPath,
            basename($archivePath) . '.pdf',
            'application/pdf',
        );
    }
}
