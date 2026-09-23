<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Attribute\Application\WritesOnRender;
use App\Twig\Components\Application\RequiresBoardWithSudoTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\PreReRender;

/**
 * A live component that applies inline edits before it renders, like the meeting administration does, and that is
 * behind sudo, so the listeners that read both answers have one component to read them from.
 */
#[WritesOnRender]
final class LiveRenderWriteDouble
{
    use RequiresBoardWithSudoTrait;

    public function __construct(private readonly Security $security)
    {
    }

    #[PreReRender]
    public function syncEdits(): void
    {
    }
}
