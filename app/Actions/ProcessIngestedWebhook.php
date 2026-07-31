<?php

namespace App\Actions;

use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineFactory;
use Illuminate\Pipeline\Pipeline;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The pipeline-level dispatch-timing action (ADR-005/007). Runs the WHOLE pipeline
 * over one {@see PipelineContext} in-process via the native
 * `Illuminate\Pipeline\Pipeline`. At item #1 it is invoked with `::run` (sync);
 * #4 flips to `::dispatch` with no handler change. No job config exists at #1.
 */
class ProcessIngestedWebhook
{
    use AsAction;

    public function __construct(private PipelineFactory $factory) {}

    public function handle(PipelineContext $ctx): void
    {
        app(Pipeline::class)
            ->send($ctx)
            ->through($this->factory->stepsFor($ctx->proxy))
            ->thenReturn();
    }
}
