<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function array_filter;
use function array_map;
use function intval;
use function max;
use function preg_replace;
use function similar_text;
use function sprintf;
use function strtolower;
use function transliterator_transliterate;
use function trim;
use function usort;

/**
 * phpcs:disable Generic.Files.LineLength.TooLong
 * phpcs:disable SlevomatCodingStandard.Functions.RequireMultiLineCall.RequiredMultiLineCall
 */
final class Version20260920150053 extends AbstractMigration
{
    /**
     * An album is dated by its first photo, which is at most a day off from the activity.
     */
    private const string DATE_TOLERANCE = 'P1D';

    /**
     * The date already narrows the candidates to the activities of those days, so the name only has to confirm the
     * match and may differ by more than a typo, such as a "day 1" suffix or a "GEWIS" prefix.
     */
    private const float MINIMUM_SIMILARITY = 0.5;

    /**
     * When several activities fall on the same days, the album is linked to the closest name only if it is clearly
     * closer than the next one. A near tie is left for somebody to decide.
     */
    private const float MINIMUM_MARGIN = 0.2;

    public function getDescription(): string
    {
        return 'Link albums to the activity their photos were taken at, and link the existing albums whose date and'
            . ' name single out one activity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Album ADD activity_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE Album ADD CONSTRAINT FK_F859414781C06096 FOREIGN KEY (activity_id) REFERENCES Activity (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F859414781C06096 ON Album (activity_id)');
    }

    public function postUp(Schema $schema): void
    {
        $activities = array_map(
            static fn (array $row): array => [
                'id' => intval($row['id']),
                'from' => new DateTimeImmutable($row['beginTime'])->sub(new DateInterval(self::DATE_TOLERANCE)),
                'until' => new DateTimeImmutable($row['endTime'])->add(new DateInterval(self::DATE_TOLERANCE)),
                'names' => array_filter([
                    self::normalise((string) $row['valueEN']),
                    self::normalise((string) $row['valueNL']),
                ]),
                'label' => (string) ($row['valueEN'] ?? $row['valueNL']),
            ],
            $this->connection->fetchAllAssociative(
                'SELECT a.id, r.beginTime, r.endTime, n.valueEN, n.valueNL'
                . ' FROM Activity a'
                . ' JOIN ActivityRevision r ON r.id = a.liveRevision_id'
                . ' JOIN ActivityLocalisedText n ON n.id = r.name_id'
                . ' WHERE a.unpublishedAt IS NULL',
            ),
        );

        $albums = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, parent_id, name, startDateTime FROM Album') as $row) {
            $albums[intval($row['id'])] = [
                'id' => intval($row['id']),
                'parent' => null === $row['parent_id'] ? null : intval($row['parent_id']),
                'name' => (string) $row['name'],
                'start' => null === $row['startDateTime'] ? null : new DateTimeImmutable($row['startDateTime']),
            ];
        }

        // Parents before children, so an ancestor's link is known by the time a sub-album is considered.
        $linked = [];
        $pending = $albums;
        while ([] !== $pending) {
            foreach ($pending as $id => $album) {
                if (
                    null !== $album['parent']
                    && isset($pending[$album['parent']])
                ) {
                    continue;
                }

                unset($pending[$id]);

                $activity = self::match($album, $activities);
                if (null === $activity) {
                    continue;
                }

                if (self::inherited($album, $albums, $linked) === $activity['id']) {
                    continue;
                }

                $linked[$id] = $activity['id'];
                $this->connection->update('Album', ['activity_id' => $activity['id']], ['id' => $id]);
                $this->write(sprintf('Linked album %d "%s" to activity %d "%s".', $id, $album['name'], $activity['id'], $activity['label']));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Album DROP FOREIGN KEY FK_F859414781C06096');
        $this->addSql('DROP INDEX IDX_F859414781C06096 ON Album');
        $this->addSql('ALTER TABLE Album DROP activity_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * @param array{id: int, name: string, start: ?DateTimeImmutable}                                                 $album
     * @param list<array{id: int, from: DateTimeImmutable, until: DateTimeImmutable, names: string[], label: string}> $activities
     *
     * @return ?array{id: int, from: DateTimeImmutable, until: DateTimeImmutable, names: string[], label: string}
     */
    private static function match(
        array $album,
        array $activities,
    ): ?array {
        $start = $album['start'];
        $name = self::normalise($album['name']);
        if (
            null === $start
            || '' === $name
        ) {
            return null;
        }

        $candidates = [];
        foreach ($activities as $activity) {
            if (
                $start < $activity['from']
                || $start > $activity['until']
            ) {
                continue;
            }

            $similarity = 0.0;
            foreach ($activity['names'] as $activityName) {
                similar_text($name, $activityName, $percent);
                $similarity = max($similarity, $percent / 100);
            }

            $candidates[] = [
                $similarity,
                $activity,
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        if (
            [] === $candidates
            || $candidates[0][0] < self::MINIMUM_SIMILARITY
        ) {
            return null;
        }

        if (
            isset($candidates[1])
            && $candidates[0][0] - $candidates[1][0] < self::MINIMUM_MARGIN
        ) {
            return null;
        }

        return $candidates[0][1];
    }

    /**
     * @param array{parent: ?int}             $album
     * @param array<int, array{parent: ?int}> $albums
     * @param array<int, int>                 $linked
     */
    private static function inherited(
        array $album,
        array $albums,
        array $linked,
    ): ?int {
        $parent = $album['parent'];
        while (null !== $parent) {
            if (isset($linked[$parent])) {
                return $linked[$parent];
            }

            $parent = $albums[$parent]['parent'] ?? null;
        }

        return null;
    }

    /**
     * Lower-case ASCII words. A year repeats what the album's date already gives, and the association's own name is
     * in many names, so neither counts towards the similarity.
     */
    private static function normalise(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?: $name;
        $words = preg_replace(
            [
                '/[^a-z0-9]+/',
                '/\b(?:19|20)[0-9]{2}\b/',
                '/\bgewis\b/',
                '/\s+/',
            ],
            ' ',
            strtolower($ascii),
        ) ?? '';

        return trim($words);
    }
}
