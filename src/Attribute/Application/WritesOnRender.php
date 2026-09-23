<?php

declare(strict_types=1);

namespace App\Attribute\Application;

use Attribute;

/**
 * Marks a live component that writes while it renders, through a
 * {@see \Symfony\UX\LiveComponent\Attribute\PreReRender} method that applies what the reader edited inline.
 *
 * A re-render invokes no action, and it is a GET where the props fit in the address, so neither the action nor the
 * method states that such a request writes. {@see \App\Service\Application\LiveComponentAction} reads this instead,
 * and every request to the component counts as a write.
 *
 * Absence is not the safe default it is for {@see ReadOnlySafe}, because nothing can be read from a component that
 * writes without declaring it: its re-renders are let through a read-only window and do not extend a sudo grant. A
 * component that renders without writing declares nothing.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WritesOnRender
{
}
