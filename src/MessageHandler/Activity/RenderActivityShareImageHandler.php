<?php

declare(strict_types=1);

namespace App\MessageHandler\Activity;

use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\ImageProfile;
use App\Entity\Application\Enums\Languages;
use App\Entity\Application\Enums\StorageNamespace;
use App\Message\Activity\RenderActivityShareImageMessage;
use App\Repository\Activity\ActivityRevisionRepository;
use App\Service\Activity\ActivityShareImageRenderer;
use App\Service\Application\FileStorage;
use App\Service\Application\VariantGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function hash;
use function sprintf;

#[AsMessageHandler]
class RenderActivityShareImageHandler
{
    public function __construct(
        private readonly ActivityRevisionRepository $revisionRepository,
        private readonly ActivityShareImageRenderer $renderer,
        private readonly FileStorage $fileStorage,
        private readonly VariantGenerator $variantGenerator,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RenderActivityShareImageMessage $message): void
    {
        $revision = $this->revisionRepository->find($message->getRevisionId());
        if (null === $revision) {
            return;
        }

        $now = new DateTimeImmutable();
        $paths = [];
        foreach (Languages::cases() as $language) {
            $bytes = $this->renderer->render(
                $revision,
                $language,
                $now,
            );
            // Content-addressed like the album covers, so an unchanged card keeps its URL and the crawler's cache.
            $path = sprintf(
                '%s/%s.png',
                StorageNamespace::ActivityShareImage->directory(),
                hash(
                    'sha256',
                    $bytes,
                ),
            );
            $this->fileStorage->write(
                $path,
                $bytes,
            );
            $paths[$language->getLangParam()] = $path;
        }

        $activity = $revision->activity;
        $activity->shareImagePaths = $paths;
        $activity->shareImageStaleAt = $activity->isCancelled()
            ? null
            : SignupList::nextTransitionAmong(
                $revision->getSignupLists(),
                $now,
            );
        $this->entityManager->flush();

        // Drawn here rather than on the first request, which is a crawler that does not retry.
        foreach ($paths as $path) {
            $this->variantGenerator->generate(
                $path,
                ImageProfile::ActivityShare,
            );
        }
    }
}
