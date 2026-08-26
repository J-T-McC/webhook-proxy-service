<?php

namespace App\Enums;

use Carbon\CarbonInterval;

/**
 * The three windows every analytics figure (item #11) can be read over.
 * Not persisted anywhere — its purpose is to make an unrecognised `window`
 * query parameter impossible to propagate (`tryFrom($value) ?? default()`),
 * rather than merely validated against (plan-11 Technical ruling 8).
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
     * The window's length as a Carbon interval.
     */
    public function interval(): CarbonInterval
    {
        return match ($this) {
            self::TwentyFourHours => CarbonInterval::hours(24),
            self::SevenDays => CarbonInterval::days(7),
            self::ThirtyDays => CarbonInterval::days(30),
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
