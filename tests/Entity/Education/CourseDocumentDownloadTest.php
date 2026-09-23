<?php

declare(strict_types=1);

namespace App\Tests\Entity\Education;

use App\Entity\Education\CourseDocumentDownload;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Who may collect a built download.
 *
 * The token is unguessable, but possession of it cannot be enough on its own: the file it leads to names the member
 * who requested it, so giving it to another user would let them pass a document around under that member's name. That
 * is precisely the attribution the watermark exists to provide, so it is pinned here.
 */
final class CourseDocumentDownloadTest extends TestCase
{
    public function testAMemberCollectsTheirOwnDownload(): void
    {
        $download = $this->download($this->user(8000));

        self::assertTrue($download->isCollectableBy(
            $this->user(8000),
            '192.0.2.1',
        ));
    }

    public function testAnotherMemberMayNotCollectIt(): void
    {
        $download = $this->download($this->user(8000));

        self::assertFalse($download->isCollectableBy(
            $this->user(8001),
            '192.0.2.1',
        ));
    }

    public function testAnAnonymousVisitorMayNotCollectAMembersDownload(): void
    {
        $download = $this->download($this->user(8000));

        self::assertFalse($download->isCollectableBy(
            null,
            '131.155.10.7',
        ));
    }

    /**
     * An anonymous request from campus is only identified by where it came from, so that is what has to match.
     */
    public function testAnAnonymousDownloadIsCollectedFromTheSameAddress(): void
    {
        $download = $this->download(
            null,
            '131.155.10.7',
        );

        self::assertTrue($download->isCollectableBy(
            null,
            '131.155.10.7',
        ));
        self::assertFalse($download->isCollectableBy(
            null,
            '131.155.10.8',
        ));
    }

    /**
     * Logging in between asking and collecting makes it a different requester, and the file already names the address.
     */
    public function testAMemberMayNotCollectAnAnonymousDownload(): void
    {
        $download = $this->download(
            null,
            '131.155.10.7',
        );

        self::assertFalse($download->isCollectableBy(
            $this->user(8000),
            '131.155.10.7',
        ));
    }

    private function download(
        ?User $user,
        string $clientIp = '192.0.2.1',
    ): CourseDocumentDownload {
        $download = new CourseDocumentDownload();
        $download->token = Uuid::v4();
        $download->requestedBy = $user;
        $download->requestedFrom = $clientIp;

        return $download;
    }

    private function user(int $lidnr): User
    {
        $user = new User();
        $user->lidnr = $lidnr;

        return $user;
    }
}
