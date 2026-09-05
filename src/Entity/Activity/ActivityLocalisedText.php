<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\LocalisedText;
use Doctrine\ORM\Mapping\Entity;

/**
 * {@link LocalisedText} for the Activity module.
 *
 * Every association to this is mapped eager. A localised text is read whenever the thing that owns it is, and left
 * lazy each one was a query of its own: a review screen ran twenty before it drew anything.
 */
#[Entity]
class ActivityLocalisedText extends LocalisedText
{
}
