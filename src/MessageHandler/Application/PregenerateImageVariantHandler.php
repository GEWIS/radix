<?php

declare(strict_types=1);

namespace App\MessageHandler\Application;

use App\Message\Application\PregenerateImageVariantMessage;
use App\Service\Application\FilePathResolver;
use App\Service\Application\VariantGenerator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PregenerateImageVariantHandler
{
    /** For a stale message whose path matches no profile: encode rather than drop the request. */
    private const int FALLBACK_QUALITY = 85;

    public function __construct(
        private readonly FilePathResolver $pathResolver,
        private readonly VariantGenerator $variantGenerator,
    ) {
    }

    public function __invoke(PregenerateImageVariantMessage $message): void
    {
        // The command dispatched the variant it resolved the profile for, so the profile the variant maps back onto
        // is the same one: a banner's box variants reach `CompanyBannerLeaderboard`/`Billboard`, a logo's widths
        // reach `CompanyLogo`. No banner-package lookup is needed here.
        $quality = $this->pathResolver->profileForPath(
            $message->getSourcePath(),
            $message->getVariant(),
        )?->webpQuality() ?? self::FALLBACK_QUALITY;

        // Skipping upscales, unlike {@see GenerateImageVariantHandler}: nobody is waiting on this variant, so a
        // narrower original is left without one rather than stored larger than it can fill.
        $this->variantGenerator->generateVariant(
            $message->getSourcePath(),
            $message->getVariant(),
            $quality,
            skipUpscale: true,
            force: $message->isForced(),
        );
    }
}
