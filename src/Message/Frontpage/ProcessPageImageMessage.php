<?php

declare(strict_types=1);

namespace App\Message\Frontpage;

/**
 * Like {@see \App\Message\Photo\ProcessImageVariantsMessage}, but the browser waits on the outcome, so the scope
 * travels along as the topic to answer on.
 */
class ProcessPageImageMessage
{
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $scope,
    ) {
    }

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function getScope(): string
    {
        return $this->scope;
    }
}
