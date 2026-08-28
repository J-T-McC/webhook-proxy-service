---
name: laravel-resources-gotchas
description: JsonResource silently discards all-numeric-keyed nested array keys unless #[PreserveKeys] is set; verify resource shape against a real HTTP response, not just toArray()
metadata:
  type: feedback
---

`Illuminate\Http\Resources\ConditionallyLoadsAttributes::removeMissingValues()` runs recursively over
every nested array inside a `JsonResource::toArray()` result and calls `array_values()` on any array
whose keys are **all** numeric — silently discarding those keys and turning a `{id: {...}}` map into an
unkeyed JSON list. This fires at every level of the resource output, not just the top, and applies even
to a single-entry array.

**Why:** discovered building `security.destinations` (a `[destinationId => {...}]` map, item #10 T32) —
the map serialized correctly when calling `$resource->toArray($request)` directly and re-`json_encode`ing
it by hand, but silently lost every key once served through the real `->response()`/Inertia pipeline. A
unit-level check of `toArray()`'s return value would never have caught this; only checking the actual HTTP
response body did.

**Fix:** add `#[\Illuminate\Http\Resources\Attributes\PreserveKeys]` on the resource class (Laravel 11+).
It's class-scoped but safe to apply broadly — it only changes behaviour for a nested array whose keys are
already *all* numeric to begin with, so it never affects a sibling sub-object keyed by string names.

**How to apply:** any time a `JsonResource` (or a prop built by one) returns a map keyed by an id/numeric
key — not a plain list — add `#[PreserveKeys]` and verify by hitting the real endpoint/response, not just
asserting on the resource's `toArray()` return value in isolation.
