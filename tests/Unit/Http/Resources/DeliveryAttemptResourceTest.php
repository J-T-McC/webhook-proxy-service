<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\DeliveryAttemptResource;
use App\Models\DeliveryAttempt;
use Tests\TestCase;

/**
 * T25 — `DeliveryAttemptResource`: never-content field mapping (AC12; ADR-017
 * Decision 5). `DeliveryAttempt` is payload-free by construction (ADR-003),
 * so the never-body/headers assertion is structural here.
 */
class DeliveryAttemptResourceTest extends TestCase
{
    public function test_it_maps_the_expected_fields(): void
    {
        $attempt = DeliveryAttempt::factory()->succeeded()->createQuietly(['attempt_number' => 2]);

        $array = (new DeliveryAttemptResource($attempt))->resolve(request());

        $this->assertEquals([
            'attempt_number' => 2,
            'status' => $attempt->status->value,
            'http_status' => $attempt->http_status,
            'error_summary' => $attempt->error_summary,
            'started_at' => $attempt->started_at,
            'duration_ms' => $attempt->duration_ms,
        ], $array);
    }

    public function test_it_never_emits_body_or_headers(): void
    {
        $attempt = DeliveryAttempt::factory()->failed()->createQuietly();

        $array = (new DeliveryAttemptResource($attempt))->resolve(request());

        $this->assertArrayNotHasKey('body', $array);
        $this->assertArrayNotHasKey('headers', $array);
    }
}
