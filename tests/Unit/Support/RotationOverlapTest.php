<?php

namespace Tests\Unit\Support;

use App\Support\RotationOverlap;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * T13 (AC29): the fixed 24-hour overlap window, not configurable anywhere.
 */
class RotationOverlapTest extends TestCase
{
    public function test_hours_is_twenty_four(): void
    {
        $this->assertSame(24, RotationOverlap::HOURS);
    }

    public function test_hours_is_final_and_not_overridable_at_runtime(): void
    {
        $constant = new ReflectionClassConstant(RotationOverlap::class, 'HOURS');

        $this->assertTrue($constant->isFinal());
    }
}
