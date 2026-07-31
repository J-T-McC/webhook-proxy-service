<?php

namespace Tests\Unit\Pipeline;

use App\Actions\DeliverStep;
use App\Enums\ProxyMode;
use App\Models\Proxy;
use App\Pipeline\PipelineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_mode_returns_exactly_the_deliver_step(): void
    {
        $proxy = Proxy::factory()->create(['mode' => ProxyMode::Simple]);

        $steps = (new PipelineFactory)->stepsFor($proxy);

        $this->assertCount(1, $steps);
        $this->assertInstanceOf(DeliverStep::class, $steps[0]);
    }

    public function test_enhanced_mode_returns_the_identical_single_step_list(): void
    {
        $proxy = Proxy::factory()->enhanced()->create();

        $steps = (new PipelineFactory)->stepsFor($proxy);

        $this->assertCount(1, $steps);
        $this->assertInstanceOf(DeliverStep::class, $steps[0]);
    }
}
