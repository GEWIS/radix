<?php

declare(strict_types=1);

namespace App\Message\Application;

use App\Entity\Application\Enums\ImageVariant;

/**
 * One variant to encode as part of a backfill, dispatched by `app:image:pregenerate` onto the `images` transport so
 * the encoding lands in the image workers instead of in whichever container the command was run from.
 *
 * Deliberately not {@see GenerateImageVariantMessage}: the serving path raises that one on a miss, and the two want
 * opposite things from an original narrower than the target. A miss must still yield something to serve, a backfill
 * must not store an upscale. Keeping them apart also means neither has to grow a field, which the default
 * `PhpSerializer` would leave uninitialised on payloads already in flight on the broker.
 */
class PregenerateImageVariantMessage
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly ImageVariant $variant,
        /** Re-encode over a variant that is already cached, for a changed variant set, quality or encoder. */
        private readonly bool $force = false,
    ) {
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getVariant(): ImageVariant
    {
        return $this->variant;
    }

    public function isForced(): bool
    {
        return $this->force;
    }
}
