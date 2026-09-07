<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use App\Entity\Application\RevisionInterface;

use function count;

final readonly class ReviewBaseline
{
    public const string LIVE = 'live';

    public const string PREVIOUS = 'previous';

    /**
     * @param list<ReviewBaselineChoice> $choices empty when there is nothing to read against, one entry when there is
     *                                            no choice to make
     */
    private function __construct(
        public ?RevisionInterface $revision,
        public array $choices,
    ) {
    }

    public static function resolve(
        RevisionInterface $revision,
        string $asked,
    ): self {
        $live = $revision->getLiveCounterpart();
        $previous = $revision->getPreviousRevision();

        if (
            null !== $live
            && null !== $previous
            && $live !== $previous
        ) {
            $against = self::PREVIOUS === $asked
                ? $previous
                : $live;

            return new self(
                $against,
                [
                    new ReviewBaselineChoice(
                        self::LIVE,
                        $live->getRevisionNumber(),
                        true,
                        $against === $live,
                    ),
                    new ReviewBaselineChoice(
                        self::PREVIOUS,
                        $previous->getRevisionNumber(),
                        false,
                        $against === $previous,
                    ),
                ],
            );
        }

        $against = $live ?? $previous;

        return new self(
            $against,
            null === $against ? [] : [
                new ReviewBaselineChoice(
                    null === $live ? self::PREVIOUS : self::LIVE,
                    $against->getRevisionNumber(),
                    null !== $live,
                    true,
                ),
            ],
        );
    }

    public function isChoice(): bool
    {
        return count($this->choices) > 1;
    }
}
