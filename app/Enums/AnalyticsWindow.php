<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The three windows every analytics figure (item #11) can be read over.
 * Not persisted anywhere — its purpose is to make an unrecognised `window`
 * query parameter impossible to propagate (`tryFrom($value) ?? default()`),
 * rather than merely validated against (plan-11 Technical ruling 8).
 *
 * Also the single place the window's bucket size and the window's range are
 * decided (plan-11 Technical rulings 11, 12) — nothing outside `bucket()`
 * may construct a `SeriesBucket` from a window value, and nothing outside
 * `start()` may build a window's range bound.
 */
enum AnalyticsWindow: string
{
    case TwentyFourHours = '24h';
    case SevenDays = '7d';
    case ThirtyDays = '30d';

    /**
     * The display label for this window.
     */
    public function label(): string
    {
        return match ($this) {
            self::TwentyFourHours => '24 hours',
            self::SevenDays => '7 days',
            self::ThirtyDays => '30 days',
        };
    }

    /**
     * The window's length in whole days.
     */
    public function days(): int
    {
        return match ($this) {
            self::TwentyFourHours => 1,
            self::SevenDays => 7,
            self::ThirtyDays => 30,
        };
    }

    /**
     * The bucket size the trend series densifies to on this window
     * (Amendment B(i); Technical ruling 11) — `Hour` on `24h`, `Day` on
     * `7d`/`30d`.
     */
    public function bucket(): SeriesBucket
    {
        return match ($this) {
            self::TwentyFourHours => SeriesBucket::Hour,
            self::SevenDays, self::ThirtyDays => SeriesBucket::Day,
        };
    }

    /**
     * The number of points the trend series densifies to on this window —
     * 24 hourly points on `24h`, 7/30 daily points on `7d`/`30d` (Technical
     * ruling 11).
     */
    public function bucketCount(): int
    {
        return match ($this) {
            self::TwentyFourHours => 24,
            self::SevenDays => 7,
            self::ThirtyDays => 30,
        };
    }

    /**
     * The window's inclusive start — the first bucket's start (Technical
     * ruling 12). This is the *only* window-range definition in the
     * feature: every figure, the series, and the Events list's window
     * filter resolve their range through this method against the same
     * `$now`, so a record the headline figure counts and a record the
     * series' buckets partition are always drawn from the same range.
     */
    public function start(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::TwentyFourHours => $now->startOfHour()->subHours(23),
            self::SevenDays => $now->startOfDay()->subDays(6),
            self::ThirtyDays => $now->startOfDay()->subDays(29),
        };
    }

    /**
     * The default window (AC17) — resolved by every controller as
     * `AnalyticsWindow::tryFrom($value) ?? AnalyticsWindow::default()`.
     */
    public static function default(): self
    {
        return self::ThirtyDays;
    }
}
