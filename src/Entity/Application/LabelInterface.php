<?php

declare(strict_types=1);

namespace App\Entity\Application;

/**
 * A label that the revisions of one domain are tagged with. Shared reference data rather than revisable content, so
 * renaming one changes it everywhere it is used, and one that is in use is retired rather than removed, because the
 * revisions it is on cannot be changed.
 */
interface LabelInterface
{
    public ?int $id { get; }

    public LocalisedText $name { get; }

    public bool $retired { get; }

    /**
     * The class this label's name is stored in, so the pair is stated on the label itself rather than wherever a
     * label is built.
     *
     * @return class-string<LocalisedText>
     */
    public static function textClass(): string;

    public function isInUse(): bool;

    public function retire(): void;

    public function restore(): void;
}
