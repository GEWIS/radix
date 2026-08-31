<?php

declare(strict_types=1);

namespace App\Form\Database;

use App\Entity\Database\MailingList;
use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Label of a mailing list checkbox on the registration form and on a member's own settings page.
 *
 * The name goes on its own line above a muted description, so the two read as a heading and its explanation rather
 * than as one run-on sentence. Both come from the database and are escaped here, as the label is rendered as HTML.
 *
 * The description is not a translatable string but a pair of columns on the list itself, so which one to show can
 * only be decided once the locale the form renders in is known.
 *
 * A subscription that is waiting to be carried to Mailman or Listmonk says so after the name, because that is why
 * the checkbox beside it cannot be moved.
 */
final readonly class MailingListLabel implements TranslatableInterface
{
    public function __construct(
        private MailingList $list,
        private ?TranslatableInterface $state = null,
    ) {
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        $description = 'en' === ($locale ?? $translator->getLocale())
            ? $this->list->getEnDescription()
            : $this->list->getNlDescription();

        $name = htmlspecialchars(
            $this->list->getName(),
            ENT_QUOTES | ENT_SUBSTITUTE,
        );

        if (null !== $this->state) {
            $name .= sprintf(
                ' <span class="fw-normal text-body-secondary">(%s)</span>',
                htmlspecialchars(
                    $this->state->trans(
                        $translator,
                        $locale,
                    ),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                ),
            );
        }

        return sprintf(
            '<span class="d-block fw-semibold">%s</span><span class="d-block small text-body-secondary">%s</span>',
            $name,
            htmlspecialchars(
                $description,
                ENT_QUOTES | ENT_SUBSTITUTE,
            ),
        );
    }
}
