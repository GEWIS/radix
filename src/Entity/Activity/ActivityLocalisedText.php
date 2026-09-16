<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\LocalisedText;
use Doctrine\ORM\Mapping\Entity;

/**
 * {@link LocalisedText} for the Activity module.
 *
 * Every association to this is mapped eager. A localised text is read whenever its owner is, and with lazy loading
 * each one was a query of its own: a review screen ran twenty queries before rendering.
 */
#[Entity]
class ActivityLocalisedText extends LocalisedText
{
}
