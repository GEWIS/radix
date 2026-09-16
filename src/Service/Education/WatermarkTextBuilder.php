<?php

declare(strict_types=1);

namespace App\Service\Education;

use App\Entity\Education\CourseDocumentDownload;

use function sprintf;

/**
 * Names who requested the file and when, so a copy that appears elsewhere identifies the account that downloaded it.
 * The wording is deliberately the same as the previous site used, so copies from either era read alike.
 */
final readonly class WatermarkTextBuilder
{
    public function __construct(private string $siteUrl)
    {
    }

    public function forDownload(CourseDocumentDownload $download): string
    {
        return sprintf(
            'This document was downloaded on %s by %s via %s.',
            $download->requestedAt->format('Y-m-d H:i:s'),
            $download->requestedByName,
            $this->siteUrl,
        );
    }
}
