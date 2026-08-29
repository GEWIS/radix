<?php

declare(strict_types=1);

namespace App\MessageHandler\Frontpage;

use App\Entity\Application\Enums\ImageProfile;
use App\Message\Frontpage\ProcessPageImageMessage;
use App\Service\Application\VariantGenerator;
use App\Service\Frontpage\PageImageStore;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Reports back whatever happens: an image nobody hears about stays unplaceable until its marker expires.
 */
#[AsMessageHandler]
class ProcessPageImageHandler
{
    public function __construct(
        private readonly VariantGenerator $variantGenerator,
        private readonly PageImageStore $pageImageStore,
        private readonly HubInterface $hub,
    ) {
    }

    public function __invoke(ProcessPageImageMessage $message): void
    {
        $path = $message->getSourcePath();

        try {
            $this->variantGenerator->generate(
                $path,
                ImageProfile::PageImage,
            );
        } finally {
            $this->pageImageStore->settle($path);

            $this->hub->publish(new Update(
                $this->pageImageStore->topic($message->getScope()),
                json_encode(
                    [
                        'status' => 'ready',
                        'path' => $path,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
                private: true,
            ));
        }
    }
}
