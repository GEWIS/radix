<?php

declare(strict_types=1);

namespace App\MessageHandler\Application;

use App\Message\Application\GenerateImageVariantMessage;
use App\Service\Application\FilePathResolver;
use App\Service\Application\VariantGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GenerateImageVariantHandler
{
    /** For a stale message whose path matches no profile: encode rather than drop the request. */
    private const int FALLBACK_QUALITY = 85;

    public function __construct(
        private readonly FilePathResolver $pathResolver,
        private readonly VariantGenerator $variantGenerator,
    ) {
    }

    public function __invoke(GenerateImageVariantMessage $message): void
    {
        $quality = $this->pathResolver->profileForPath(
            $message->getSourcePath(),
            $message->getVariant(),
        )?->webpQuality() ?? self::FALLBACK_QUALITY;

        // Not skipping upscales: this variant was asked for, so a narrower original must still yield one (capped)
        // instead of an eternal miss.
        $this->variantGenerator->generateVariant(
            $message->getSourcePath(),
            $message->getVariant(),
            $quality,
            skipUpscale: false,
        );
    }
}
