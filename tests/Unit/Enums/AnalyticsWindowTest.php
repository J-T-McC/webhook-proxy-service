<?php

namespace Tests\Unit\Enums;

use App\Enums\AnalyticsWindow;
use App\Enums\SeriesBucket;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AnalyticsWindowTest extends TestCase
{
    public function test_it_has_exactly_the_three_documented_cases(): void
    {
        $this->assertSame(
            ['24h', '7d', '30d'],
            array_map(fn (AnalyticsWindow $c) => $c->value, AnalyticsWindow::cases()),
        );
    }

    public function test_days_returns_one_seven_thirty(): void
    {
        $this->assertSame(1, AnalyticsWindow::TwentyFourHours->days());
        $this->assertSame(7, AnalyticsWindow::SevenDays->days());
        $this->assertSame(30, AnalyticsWindow::ThirtyDays->days());
    }

    public function test_label_returns_a_string_per_case(): void
    {
        $this->assertSame('24 hours', AnalyticsWindow::TwentyFourHours->label());
        $this->assertSame('7 days', AnalyticsWindow::SevenDays->label());
        $this->assertSame('30 days', AnalyticsWindow::ThirtyDays->label());
    }

    public function test_bucket_is_hour_on_twenty_four_hours_and_day_on_the_two_day_windows(): void
    {
        $this->assertSame(SeriesBucket::Hour, AnalyticsWindow::TwentyFourHours->bucket());
        $this->assertSame(SeriesBucket::Day, AnalyticsWindow::SevenDays->bucket());
        $this->assertSame(SeriesBucket::Day, AnalyticsWindow::ThirtyDays->bucket());
    }

    public function test_bucket_count_returns_twenty_four_seven_thirty(): void
    {
        $this->assertSame(24, AnalyticsWindow::TwentyFourHours->bucketCount());
        $this->assertSame(7, AnalyticsWindow::SevenDays->bucketCount());
        $this->assertSame(30, AnalyticsWindow::ThirtyDays->bucketCount());
    }

    public function test_start_returns_the_first_bucket_start_per_window(): void
    {
        $now = CarbonImmutable::create(2026, 8, 26, 14, 37, 22);

        $this->assertTrue(
            $now->startOfHour()->subHours(23)->equalTo(AnalyticsWindow::TwentyFourHours->start($now)),
        );
        $this->assertTrue(
            $now->startOfDay()->subDays(6)->equalTo(AnalyticsWindow::SevenDays->start($now)),
        );
        $this->assertTrue(
            $now->startOfDay()->subDays(29)->equalTo(AnalyticsWindow::ThirtyDays->start($now)),
        );
    }

    public function test_default_returns_thirty_days(): void
    {
        $this->assertSame(AnalyticsWindow::ThirtyDays, AnalyticsWindow::default());
    }

    public function test_try_from_on_a_garbage_string_returns_null(): void
    {
        $this->assertNull(AnalyticsWindow::tryFrom('garbage'));
    }

    public function test_interval_no_longer_exists_on_the_enum(): void
    {
        $this->assertFalse(method_exists(AnalyticsWindow::class, 'interval'));
    }
}
