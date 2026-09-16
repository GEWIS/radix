<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Twig\Components\Application\RequiresBoardWithSudoTrait;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\TwigComponent\ComponentFactory;

use function in_array;
use function is_string;

/**
 * The live components that are behind sudo.
 *
 * A component is served under `/_components`, outside the addresses {@see SudoArea} names, so one that is only
 * reachable from the administration requires the grant itself: with `#[IsGranted(SudoVoter::ATTRIBUTE)]` on the
 * class, or, where a board seat is required beside it, through {@see RequiresBoardWithSudoTrait}.
 *
 * Read by {@see \App\EventListener\User\SudoRefreshListener}, which extends a grant for a write to one of these and
 * not for a write to any other component: a vote or a sign-up is a write like any other, and a page holding one would
 * otherwise keep the administration open for as long as it was left there. A component this cannot place is answered
 * with false, which costs a user a prompt they need not have had and never keeps a grant open longer.
 */
final readonly class SudoComponents
{
    public function __construct(
        #[Autowire(service: 'ux.twig_component.component_factory')]
        private ComponentFactory $components,
    ) {
    }

    public function covers(Request $request): bool
    {
        $name = $request->attributes->get('_live_component');
        if (!is_string($name)) {
            return false;
        }

        try {
            $component = new ReflectionClass($this->components->metadataFor($name)->getClass());
        } catch (InvalidArgumentException | ReflectionException) {
            return false;
        }

        foreach ($component->getAttributes(IsGranted::class) as $attribute) {
            if (SudoVoter::ATTRIBUTE === $attribute->newInstance()->attribute) {
                return true;
            }
        }

        return in_array(
            RequiresBoardWithSudoTrait::class,
            $component->getTraitNames(),
            true,
        );
    }
}
