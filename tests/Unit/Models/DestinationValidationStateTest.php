<?php

namespace Tests\Unit\Models;

use App\Enums\DestinationValidationState;
use App\Enums\DestinationValidationStatus;
use App\Models\Destination;
use Tests\TestCase;

/**
 * The stored validation state, the derived four-state display status, and the
 * gate scope every enforcement point shares (#18 AC1, AC2, AC8, AC30).
 */
class DestinationValidationStateTest extends TestCase
{
    public function test_a_new_destination_is_unvalidated_by_default(): void
    {
        // Deliberately not the factory: the factory defaults to validated,
        // because it models a destination that works for every other feature's
        // tests. What must be unvalidated is a destination the application
        // itself creates.
        $this->assertSame(
            DestinationValidationState::Unvalidated,
            (new Destination)->validation_state,
            'A destination must start unvalidated. The AC30 backfill grandfathers rows that '
            .'existed at migration time and must not leak into the default for new ones.',
        );
    }

    public function test_validation_state_casts_to_enum(): void
    {
        $destination = Destination::factory()->validated()->createQuietly();

        $this->assertInstanceOf(DestinationValidationState::class, $destination->fresh()->validation_state);
    }

    public function test_status_is_unvalidated_when_no_challenge_has_been_sent(): void
    {
        $destination = Destination::factory()->unvalidated()->createQuietly();

        $this->assertSame(DestinationValidationStatus::Unvalidated, $destination->validationStatus());
    }

    public function test_status_is_pending_while_the_challenge_window_is_open(): void
    {
        $destination = Destination::factory()->pendingValidation()->createQuietly();

        $this->assertSame(DestinationValidationStatus::Pending, $destination->validationStatus());
    }

    public function test_status_is_expired_once_the_challenge_window_has_closed(): void
    {
        $destination = Destination::factory()->expiredValidation()->createQuietly();

        $this->assertSame(
            DestinationValidationStatus::Expired,
            $destination->validationStatus(),
            'Expired is derived, not stored — a pending challenge whose expiry has passed.',
        );

        $this->assertSame(
            DestinationValidationState::Pending,
            $destination->fresh()->validation_state,
            'Nothing writes an expired state; the stored column stays pending.',
        );
    }

    public function test_status_is_validated_once_approved(): void
    {
        $destination = Destination::factory()->validated()->createQuietly();

        $this->assertSame(DestinationValidationStatus::Validated, $destination->validationStatus());
    }

    public function test_the_gate_scope_admits_only_validated_destinations(): void
    {
        $validated = Destination::factory()->validated()->createQuietly();
        Destination::factory()->unvalidated()->createQuietly();
        Destination::factory()->pendingValidation()->createQuietly();
        Destination::factory()->expiredValidation()->createQuietly();

        $admitted = Destination::withoutGlobalScopes()->validated()->pluck('id');

        $this->assertEquals([$validated->id], $admitted->all());
    }

    public function test_an_expired_challenge_is_not_admitted_by_the_gate(): void
    {
        $destination = Destination::factory()->expiredValidation()->createQuietly();

        $this->assertFalse(
            Destination::withoutGlobalScopes()->validated()->whereKey($destination->id)->exists(),
            'An expired challenge is stored as pending, so the positive test against validated '
            .'excludes it without needing an expiry check on the gate.',
        );
    }
}
