---
name: ingest-pipeline-seams
description: How the ingest→upstream-response→dispatch path is wired and the ADR-005 async placement gotcha
metadata:
  type: project
---

The ingest hot path (`app/Http/Controllers/IngestController.php`): resolve proxy by
SHA-256 token-hash (unscoped by team, SoftDeletes still applies, 404 on miss) →
build `PipelineContext` (shared `ingest_id` uuid) → `ResponseResolver::resolve($proxy)`
(config-only, ADR-004) → `ProcessIngestedWebhook::run($ctx)` → return response.

**ADR-005 gotcha (load-bearing for #3+):** `ProcessIngestedWebhook` is the dispatch-timing
seam — it runs `::run` (sync) now but flips to `::dispatch` (async) at #4. Anything that
MUST happen before the upstream response is returned therefore **cannot** live inside
that pipeline (it would run after the response once #4 makes it async). `PipelineFactory`
had a commented `CaptureRawStep // #5` inside the enhanced-only front stage — that home is
wrong for always-on capture-before-response; ADR-010 moves raw capture to a synchronous
pre-dispatch step in the ingest handler instead.

**Why:** #3's capture-before-response guarantee (AC5) plus ADR-005's future async dispatch.
**How to apply:** any future "must complete before the 2xx" concern belongs in the handler
pre-dispatch, not in the ProcessIngestedWebhook pipeline. `ingest_id` is the single
correlator shared by the raw capture and the payload-free `delivery_attempts` (ADR-003) —
reuse it, don't create a parallel key.
