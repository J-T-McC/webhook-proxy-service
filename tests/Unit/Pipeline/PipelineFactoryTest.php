<?php

namespace Tests\Unit\Pipeline;

use App\Actions\CaptureDispatchedStep;
use App\Actions\DeliverStep;
use App\Enums\ProxyMode;
use App\Models\Proxy;
use App\Pipeline\PipelineFactory;
use Tests\TestCase;

class PipelineFactoryTest extends TestCase
{
    public function test_simple_mode_returns_exactly_the_deliver_step(): void
    {
        $proxy = Proxy::factory()->create(['mode' => ProxyMode::Simple]);

        $steps = (new PipelineFactory)->stepsFor($proxy);

        $this->assertCount(1, $steps);
        $this->assertInstanceOf(DeliverStep::class, $steps[0]);
    }

    public function test_enhanced_mode_returns_capture_dispatched_step_then_deliver_step(): void
    {
        $proxy = Proxy::factory()->enhanced()->create();

        $steps = (new PipelineFactory)->stepsFor($proxy);

        $this->assertCount(2, $steps);
        $this->assertInstanceOf(CaptureDispatchedStep::class, $steps[0]);
        $this->assertInstanceOf(DeliverStep::class, $steps[1]);
    }
}
