<?php

declare(strict_types=1);

namespace App\Tests\Entity\Photo;

use App\Entity\Activity\Activity;
use App\Entity\Photo\Album;
use PHPUnit\Framework\TestCase;

/**
 * A sub-album without an activity of its own is linked through its ancestors.
 */
final class AlbumTest extends TestCase
{
    public function testLinkedActivityComesFromTheNearestAncestor(): void
    {
        $activity = new Activity();
        $root = new Album();
        $root->activity = $activity;
        $child = new Album();
        $child->setParent($root);
        $grandchild = new Album();
        $grandchild->setParent($child);

        self::assertSame(
            $activity,
            $grandchild->getLinkedActivity(),
        );
    }

    public function testOwnActivityWinsOverTheAncestors(): void
    {
        $root = new Album();
        $root->activity = new Activity();
        $child = new Album();
        $child->setParent($root);
        $child->activity = new Activity();

        self::assertSame(
            $child->activity,
            $child->getLinkedActivity(),
        );
    }

    public function testNothingIsLinkedWithoutAnActivityInTheChain(): void
    {
        $root = new Album();
        $child = new Album();
        $child->setParent($root);

        self::assertNull($child->getLinkedActivity());
    }
}
