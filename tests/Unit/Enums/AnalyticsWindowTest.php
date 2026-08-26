<?php

namespace Tests\Unit\Enums;

use App\Enums\AnalyticsWindow;
use Carbon\CarbonInterval;
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

    public function test_interval_returns_a_carbon_interval_matching_days(): void
    {
        $this->assertInstanceOf(CarbonInterval::class, AnalyticsWindow::TwentyFourHours->interval());

        $this->assertEqualsWithDelta(24.0, AnalyticsWindow::TwentyFourHours->interval()->totalHours, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(7.0, AnalyticsWindow::SevenDays->interval()->totalDays, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(30.0, AnalyticsWindow::ThirtyDays->interval()->totalDays, PHP_FLOAT_EPSILON);
    }

    public function test_default_returns_thirty_days(): void
    {
        $this->assertSame(AnalyticsWindow::ThirtyDays, AnalyticsWindow::default());
    }

    public function test_try_from_on_a_garbage_string_returns_null(): void
    {
        $this->assertNull(AnalyticsWindow::tryFrom('garbage'));
    }
}
