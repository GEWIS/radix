<?php

declare(strict_types=1);

namespace App\Attribute\Application;

use Attribute;

/**
 * Marks a {@see \Symfony\UX\LiveComponent\Attribute\LiveAction} that changes nothing but what the component shows:
 * the page it is on, which rows are selected, which panel is open. Live components send every action as a POST
 * regardless of what it does, so the method a request arrives with says nothing about whether it writes, and
 * {@see \App\EventListener\Application\MaintenanceListener} would otherwise refuse paging and filtering along with
 * the writes while the site is read-only.
 *
 * Absence is the safe answer: an action that does not say it is read-only is refused, so a new one that writes is
 * covered without being remembered.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ReadOnlySafe
{
}
