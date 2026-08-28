<?php

namespace App\Pipeline;

use App\Actions\CaptureDispatchedStep;
use App\Actions\DeliverStep;
use App\Enums\ProxyMode;
use App\Models\Proxy;

/**
 * The composition seam (ADR-001 spine + ADR-002 mode gate). Builds the ordered
 * pipe list for a proxy. Simple-mode proxies still get exactly `[DeliverStep]`;
 * enhanced-mode proxies additionally run `CaptureDispatchedStep` immediately
 * before it (#5, AC12/AC14 — the dispatched-output store is enhanced-only).
 * The remaining commented lines are the fixed insertion contract for later
 * items. `mode` is a pure selector — enhanced config never widens `mode`.
 */
class PipelineFactory
{
    /**
     * @return list<PipelineStep>
     */
    public function stepsFor(Proxy $proxy): array
    {
        $steps = [];

        // ---- ENHANCED-ONLY front stages (LATER ITEMS — NOT built at #1) ----
        if ($proxy->mode === ProxyMode::Enhanced) {
            // $steps[] = NormalizeStep::make();         // #9  — any format -> JSON
            // CaptureRawStep — SUPERSEDED for raw capture by IngestController +
            // WebhookEventCapture (ADR-010): raw capture is a synchronous pre-dispatch
            // step in the handler, not a pipeline step (mode-independent, AC5/AC7).
            // $steps[] = MapStep::make();               // #8  — reshape
            $steps[] = CaptureDispatchedStep::make(); // #5 — persist dispatched output
        }

        // ---- TERMINAL STEP — ITEM #1. Always present, BOTH modes. ----
        $steps[] = DeliverStep::make();

        // ---- ENHANCED-ONLY tail stage (LATER — NOT built at #1) ----
        if ($proxy->mode === ProxyMode::Enhanced) {
            // $steps[] = ChangeDetectStep::make();      // #12 — post-delivery diff
        }

        return $steps;
    }
}
