<?php

declare(strict_types=1);

namespace App\Twig\Extensions;

use App\Entity\User\Enums\ColourVision;
use App\Entity\User\User;
use Override;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `colour_vision()` to templates: the palette the current user chose, or null for the default. Anonymous
 * visitors and company users have no such setting, so they always get the default.
 */
class AccessibilityExtension extends AbstractExtension
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    /**
     * @return TwigFunction[]
     */
    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'colour_vision',
                $this->colourVision(...),
            ),
        ];
    }

    public function colourVision(): ?string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $colourVision = $user->getColourVision();

        return ColourVision::Default === $colourVision
            ? null
            : $colourVision->value;
    }
}
