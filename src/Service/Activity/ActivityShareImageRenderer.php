<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use App\Service\Application\ImageManagerProvider;
use DateTimeImmutable;
use DateTimeInterface;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Geometry\Point;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\Interfaces\FontProcessorInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\Font;
use Intervention\Image\Typography\FontFactory;
use IntlDateFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function count;
use function implode;
use function intdiv;
use function max;
use function mb_strtoupper;
use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Draws the card a shared link to an activity shows, after the design in the Claude Design project: a red date block
 * on the left, the name large, the time, the location and when the sign-ups close, in the language of the page that
 * was shared.
 */
final readonly class ActivityShareImageRenderer
{
    private const int WIDTH = 1200;
    private const int HEIGHT = 630;
    private const int DATE_BLOCK_WIDTH = 150;
    private const int PADDING_X = 68;
    private const int PADDING_Y = 58;
    private const int LOGO_SIZE = 56;

    private const int TITLE_SIZE = 86;
    private const int TITLE_SIZE_SMALL = 64;
    private const int TITLE_LINES = 3;

    private const string BACKGROUND = '#fbfbfc';
    private const string RED = '#c8102e';
    private const string INK = '#141619';
    private const string MUTED = '#3d4453';
    private const string KICKER = '#8b93a3';
    private const string GREY = '#6b7280';
    private const string HAIRLINE = '#e3e6ec';
    private const string ON_RED = '#ffffff';
    private const string ON_RED_MUTED = '#f2c3cb';
    private const string ON_GREY_MUTED = '#d6d9de';

    private const string EXTRA_BOLD = 'Inter-ExtraBold.otf';
    private const string SEMI_BOLD = 'Inter-SemiBold.otf';
    private const string REGULAR = 'Inter-Regular.otf';

    private FontProcessorInterface $fontProcessor;

    public function __construct(
        private ImageManagerProvider $imageManagerProvider,
        private TranslatorInterface $translator,
        #[Autowire('%app.share_card_font_dir%')]
        private string $fontDir,
        #[Autowire('%kernel.project_dir%/assets/images/gewis.png')]
        private string $logoPath,
    ) {
        $this->fontProcessor = $imageManagerProvider->fontProcessor();
    }

    public function render(
        ActivityRevision $revision,
        Languages $language,
        DateTimeImmutable $now,
    ): string {
        $locale = $language->getLocale();
        $cancelled = $revision->activity->isCancelled();
        $begin = $revision->beginTime ?? new DateTimeImmutable();
        $end = $revision->endTime ?? $begin;

        $manager = $this->imageManagerProvider->create();
        $canvas = $manager->createImage(
            self::WIDTH,
            self::HEIGHT,
        )->fill(self::BACKGROUND);

        $this->rectangle(
            $canvas,
            0,
            0,
            self::DATE_BLOCK_WIDTH,
            self::HEIGHT,
            $cancelled ? self::GREY : self::RED,
        );
        $this->dateBlock(
            $canvas,
            $begin,
            $end,
            $locale,
            $cancelled ? self::ON_GREY_MUTED : self::ON_RED_MUTED,
        );

        $left = self::DATE_BLOCK_WIDTH + self::PADDING_X;
        $right = self::WIDTH - self::PADDING_X;
        $contentWidth = $right - $left;

        $canvas->insert(
            $manager->decodePath($this->logoPath)->scaleDown(height: self::LOGO_SIZE),
            $left,
            self::PADDING_Y,
        );
        $name = $this->translator->trans(
            'Study Association GEWIS',
            locale: $language->getLangParam(),
        );
        $kicker = $cancelled
            ? mb_strtoupper($this->translator->trans(
                'Cancelled',
                locale: $language->getLangParam(),
            ))
            : $this->kicker(
                $revision,
                $language,
            );
        $nameHeight = $this->height(
            $name,
            self::SEMI_BOLD,
            18,
        );
        $kickerHeight = $this->height(
            $kicker,
            self::SEMI_BOLD,
            13,
        );
        // Centred on the logo like the design, from the measured heights: the drawn box of a line is taller than its
        // size, so fixed offsets put the text lower than it looks in the design.
        $headerTop = self::PADDING_Y + intdiv(
            self::LOGO_SIZE - ($nameHeight + 4 + $kickerHeight),
            2,
        );
        $this->text(
            $canvas,
            $name,
            $left + self::LOGO_SIZE + 16,
            $headerTop,
            self::SEMI_BOLD,
            18,
            self::INK,
        );
        $this->text(
            $canvas,
            $kicker,
            $left + self::LOGO_SIZE + 16,
            $headerTop + $nameHeight + 4,
            self::SEMI_BOLD,
            13,
            $cancelled ? self::RED : self::KICKER,
        );

        $hairlineY = self::HEIGHT - self::PADDING_Y - 19 - 20;
        $this->rectangle(
            $canvas,
            $left,
            $hairlineY,
            $right,
            $hairlineY + 1,
            self::HAIRLINE,
        );
        $this->footer(
            $canvas,
            $revision,
            $cancelled,
            $now,
            $locale,
            $language,
            $left,
            $right,
            $hairlineY + 20,
        );

        [
            $titleSize, $lines
        ] = $this->titleLines(
            $revision->name->getText($language) ?? '',
            $contentWidth,
        );
        $location = trim($revision->location->getText($language) ?? '');
        $groupHeight = (count($lines) * $titleSize) + 24 + 25 + ('' === $location ? 0 : 7 + 25);
        $top = max(
            self::PADDING_Y + self::LOGO_SIZE + 24,
            intdiv(
                self::PADDING_Y + self::LOGO_SIZE + $hairlineY - $groupHeight,
                2,
            ),
        );

        foreach ($lines as $index => $line) {
            $this->text(
                $canvas,
                $line,
                $left,
                $top + ($index * $titleSize),
                self::EXTRA_BOLD,
                $titleSize,
                self::INK,
            );
        }

        $detailsTop = $top + (count($lines) * $titleSize) + 24;
        $this->text(
            $canvas,
            $this->when(
                $begin,
                $end,
                $locale,
            ),
            $left,
            $detailsTop,
            self::SEMI_BOLD,
            25,
            self::INK,
        );
        if ('' !== $location) {
            $this->text(
                $canvas,
                $this->fit(
                    $location,
                    self::REGULAR,
                    25,
                    $contentWidth,
                ),
                $left,
                $detailsTop + 25 + 7,
                self::REGULAR,
                25,
                self::MUTED,
            );
        }

        return $canvas->encode(new PngEncoder())->toString();
    }

    /**
     * The block on the left, stacked and centred: one date, the two days of a span within a month, or the first and
     * the last day of a longer one.
     */
    private function dateBlock(
        ImageInterface $canvas,
        DateTimeInterface $begin,
        DateTimeInterface $end,
        string $locale,
        string $muted,
    ): void {
        $sameDay = $begin->format('Y-m-d') === $end->format('Y-m-d');
        $sameMonth = $begin->format('Y-m') === $end->format('Y-m');
        $month = fn (DateTimeInterface $date): string => mb_strtoupper($this->format(
            $date,
            'LLL',
            $locale,
        ));
        $weekday = fn (DateTimeInterface $date): string => mb_strtoupper($this->format(
            $date,
            'EEE',
            $locale,
        ));

        if ($sameDay) {
            $items = [
                [
                    $month($begin),
                    self::EXTRA_BOLD,
                    15,
                    $muted,
                    6,
                ],
                [
                    $begin->format('d'),
                    self::EXTRA_BOLD,
                    54,
                    self::ON_RED,
                    6,
                ],
                [
                    $weekday($begin),
                    self::SEMI_BOLD,
                    15,
                    $muted,
                    12,
                ],
                [
                    $begin->format('Y'),
                    self::SEMI_BOLD,
                    16,
                    $muted,
                    0,
                ],
            ];
        } elseif ($sameMonth) {
            $range = $begin->format('d') . ' - ' . $end->format('d');
            $items = [
                [
                    $month($begin),
                    self::EXTRA_BOLD,
                    15,
                    $muted,
                    6,
                ],
                [
                    $range,
                    self::EXTRA_BOLD,
                    $this->sizeToFit(
                        $range,
                        self::EXTRA_BOLD,
                        42,
                        self::DATE_BLOCK_WIDTH - 24,
                    ),
                    self::ON_RED,
                    6,
                ],
                [
                    $weekday($begin) . ' - ' . $weekday($end),
                    self::SEMI_BOLD,
                    15,
                    $muted,
                    12,
                ],
                [
                    $begin->format('Y'),
                    self::SEMI_BOLD,
                    16,
                    $muted,
                    0,
                ],
            ];
        } else {
            $items = [
                [
                    $month($begin),
                    self::EXTRA_BOLD,
                    14,
                    $muted,
                    2,
                ],
                [
                    $begin->format('d'),
                    self::EXTRA_BOLD,
                    54,
                    self::ON_RED,
                    12,
                ],
                [
                    null,
                    null,
                    2,
                    $muted,
                    12,
                ],
                [
                    $month($end),
                    self::EXTRA_BOLD,
                    14,
                    $muted,
                    2,
                ],
                [
                    $end->format('d'),
                    self::EXTRA_BOLD,
                    54,
                    self::ON_RED,
                    14,
                ],
                [
                    $begin->format('Y'),
                    self::SEMI_BOLD,
                    16,
                    $muted,
                    0,
                ],
            ];
        }

        $height = 0;
        foreach ($items as [, , $size, , $gap]) {
            $height += $size + $gap;
        }

        $centre = intdiv(
            self::DATE_BLOCK_WIDTH,
            2,
        );
        $y = intdiv(
            self::HEIGHT - $height,
            2,
        );
        foreach ($items as [$text, $face, $size, $color, $gap]) {
            if (null === $text) {
                $this->rectangle(
                    $canvas,
                    $centre - 15,
                    $y,
                    $centre + 15,
                    $y + $size,
                    $color,
                );
            } else {
                $this->text(
                    $canvas,
                    $text,
                    $centre,
                    $y,
                    $face,
                    $size,
                    $color,
                    'center',
                );
            }

            $y += $size + $gap;
        }
    }

    /**
     * The category, or "activity" for the two the site shows no badge for, and who organises it.
     */
    private function kicker(
        ActivityRevision $revision,
        Languages $language,
    ): string {
        $locale = $language->getLangParam();
        $kicker = match ($revision->category) {
            ActivityCategories::Other, ActivityCategories::Uncategorised => $this->translator->trans(
                'Activity',
                locale: $locale,
            ),
            default => $revision->category->trans(
                $this->translator,
                $locale,
            ),
        };

        $organiser = $revision->organ->abbr ?? $revision->company->name ?? null;
        if (null !== $organiser) {
            $kicker .= ' · ' . $this->translator->trans(
                'by %name%',
                ['%name%' => $organiser],
                locale: $locale,
            );
        }

        return mb_strtoupper($kicker);
    }

    /**
     * The site on the right and the state of the sign-ups on the left: when the relevant list opens or closes, or
     * that every list has closed. Nothing when there is no list, or when the activity is cancelled.
     */
    private function footer(
        ImageInterface $canvas,
        ActivityRevision $revision,
        bool $cancelled,
        DateTimeImmutable $now,
        string $locale,
        Languages $language,
        int $left,
        int $right,
        int $top,
    ): void {
        $this->text(
            $canvas,
            'gewis.nl',
            $right,
            $top,
            self::EXTRA_BOLD,
            19,
            self::INK,
            'right',
        );

        if (
            $cancelled
            || $revision->getSignupLists()->isEmpty()
        ) {
            return;
        }

        $list = SignupList::relevantAmong(
            $revision->getSignupLists(),
            $now,
        );
        if (null === $list) {
            $this->text(
                $canvas,
                $this->translator->trans(
                    'Sign-ups closed',
                    locale: $language->getLangParam(),
                ),
                $left,
                $top,
                self::REGULAR,
                19,
                self::MUTED,
            );

            return;
        }

        $opensLater = null !== $list->openDate && $list->openDate > $now;
        $label = $opensLater
            ? $this->translator->trans(
                'Sign-ups open',
                locale: $language->getLangParam(),
            )
            : $this->translator->trans(
                'Sign-ups close',
                locale: $language->getLangParam(),
            );
        $moment = $opensLater
            ? $list->openDate
            : $list->closeDate;
        if (null === $moment) {
            return;
        }

        $this->text(
            $canvas,
            $label,
            $left,
            $top,
            self::REGULAR,
            19,
            self::MUTED,
        );
        // A trailing space is not measured, so the gap between the words is added here.
        $this->text(
            $canvas,
            $this->format(
                $moment,
                'EEE d MMM, HH:mm',
                $locale,
            ),
            $left + $this->width(
                $label,
                self::REGULAR,
                19,
            ) + 6,
            $top,
            self::SEMI_BOLD,
            19,
            self::INK,
        );
    }

    /**
     * The name broken over lines that fit the width, at the large size when it fits in two lines and at the smaller
     * size otherwise. Whatever does not fit the last line is cut.
     *
     * @return array{int, list<string>}
     */
    private function titleLines(
        string $name,
        int $width,
    ): array {
        $lines = $this->wrap(
            $name,
            self::EXTRA_BOLD,
            self::TITLE_SIZE,
            $width,
        );
        if (count($lines) <= 2) {
            return [
                self::TITLE_SIZE,
                $lines,
            ];
        }

        $lines = $this->wrap(
            $name,
            self::EXTRA_BOLD,
            self::TITLE_SIZE_SMALL,
            $width,
        );
        if (count($lines) > self::TITLE_LINES) {
            $kept = array_slice(
                $lines,
                0,
                self::TITLE_LINES - 1,
            );
            $kept[] = $this->fit(
                $lines[self::TITLE_LINES - 1] . '…',
                self::EXTRA_BOLD,
                self::TITLE_SIZE_SMALL,
                $width,
            );
            $lines = $kept;
        }

        return [
            self::TITLE_SIZE_SMALL,
            $lines,
        ];
    }

    /**
     * @return list<string>
     */
    private function wrap(
        string $text,
        string $face,
        int $size,
        int $width,
    ): array {
        $words = preg_split(
            '/\s+/u',
            trim($text),
        );
        if (false === $words) {
            return [$text];
        }

        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = '' === $line
                ? $word
                : $line . ' ' . $word;
            if (
                '' !== $line
                && $this->width(
                    $candidate,
                    $face,
                    $size,
                ) > $width
            ) {
                $lines[] = $line;
                $line = $word;
                continue;
            }

            $line = $candidate;
        }

        $lines[] = $line;

        return $lines;
    }

    /**
     * The text cut, with an ellipsis, to the width.
     */
    private function fit(
        string $text,
        string $face,
        int $size,
        int $width,
    ): string {
        $characters = preg_split(
            '//u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );
        if (false === $characters) {
            return $text;
        }

        while (
            count($characters) > 1
            && $this->width(
                implode($characters),
                $face,
                $size,
            ) > $width
        ) {
            $characters = array_slice(
                $characters,
                0,
                -2,
            );
            $characters[] = '…';
        }

        return implode($characters);
    }

    /**
     * The largest size, at most the given one, at which the text is no wider than the width.
     */
    private function sizeToFit(
        string $text,
        string $face,
        int $size,
        int $width,
    ): int {
        while (
            $size > 12
            && $this->width(
                $text,
                $face,
                $size,
            ) > $width
        ) {
            $size -= 2;
        }

        return $size;
    }

    private function height(
        string $text,
        string $face,
        int $size,
    ): int {
        return $this->fontProcessor->boxSize(
            $text,
            new Font(
                $this->fontDir . '/' . $face,
                $size,
            ),
        )->height();
    }

    private function width(
        string $text,
        string $face,
        int $size,
    ): int {
        return $this->fontProcessor->boxSize(
            $text,
            new Font(
                $this->fontDir . '/' . $face,
                $size,
            ),
        )->width();
    }

    private function when(
        DateTimeInterface $begin,
        DateTimeInterface $end,
        string $locale,
    ): string {
        if ($begin->format('Y-m-d') === $end->format('Y-m-d')) {
            return $begin->format('H:i') . ' - ' . $end->format('H:i');
        }

        return $this->format(
            $begin,
            'EEE d MMM, HH:mm',
            $locale,
        ) . ' - ' . $this->format(
            $end,
            'EEE d MMM, HH:mm',
            $locale,
        );
    }

    private function format(
        DateTimeInterface $date,
        string $pattern,
        string $locale,
    ): string {
        return (string) IntlDateFormatter::formatObject(
            $date,
            $pattern,
            $locale,
        );
    }

    private function text(
        ImageInterface $canvas,
        string $text,
        int $x,
        int $y,
        string $face,
        int $size,
        string $color,
        string $align = 'left',
    ): void {
        $canvas->text(
            $text,
            $x,
            $y,
            function (FontFactory $font) use ($face, $size, $color, $align): void {
                $font->filename($this->fontDir . '/' . $face);
                $font->size($size);
                $font->color($color);
                $font->align(
                    $align,
                    'top',
                );
            },
        );
    }

    private function rectangle(
        ImageInterface $canvas,
        int $left,
        int $top,
        int $right,
        int $bottom,
        string $color,
    ): void {
        $rectangle = new Rectangle(
            $right - $left,
            $bottom - $top,
            new Point(
                $left,
                $top,
            ),
        );
        $rectangle->setBackgroundColor($color);

        $canvas->drawRectangle($rectangle);
    }
}
