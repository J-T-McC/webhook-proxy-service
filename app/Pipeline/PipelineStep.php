<?php

namespace App\Pipeline;

use Closure;

/**
 * First-party pipe contract (ADR-001/007: the contract is ours, not the package's).
 *
 * The signature is Laravel's middleware/pipe shape so the native
 * `Illuminate\Pipeline\Pipeline` drives it: a step mutates the shared
 * {@see PipelineContext} in place and MUST call `$next` to continue the chain.
 * Steps additionally use the laravel-actions `AsObject` trait for
 * `::make()`/`::run()` testability, but are typed against this interface.
 */
interface PipelineStep
{
    /**
     * @param  Closure(PipelineContext): PipelineContext  $next
     */
    public function handle(PipelineContext $ctx, Closure $next): PipelineContext;
}
