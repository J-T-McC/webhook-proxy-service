<?php

namespace Tests\Unit\Services;

use App\Enums\SecretPurpose;
use App\Exceptions\SecretUnavailableException;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use App\Services\SecretStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T14 (AC10, AC11, AC26, AC29, AC33-exclusion, AC57, AC58; plan-10 Technical
 * rulings 5, 14; ADR-021 Decisions 3, 5, 6.1) — `SecretStore` is the single
 * reader and writer of `proxy_secrets`.
 */
class SecretStoreTest extends TestCase
{
    private SecretStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SecretStore::class);
    }

    private function makeProxy(): Proxy
    {
        $team = Team::factory()->createQuietly();

        return Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
    }

    /**
     * @return array<int, array{is_current: bool|null, expires_at: string|null}>
     */
    private function rowsFor(Proxy $proxy, SecretPurpose $purpose): array
    {
        return ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->orderBy('id')
            ->get(['is_current', 'expires_at'])
            ->map(fn (ProxySecret $row): array => [
                'is_current' => $row->is_current,
                'expires_at' => $row->expires_at?->toDateTimeString(),
            ])
            ->all();
    }

    public function test_three_consecutive_replace_calls_leave_exactly_two_rows(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'secret-one');
        $this->store->replace($proxy, SecretPurpose::Signing, 'secret-two');
        $this->store->replace($proxy, SecretPurpose::Signing, 'secret-three');

        $this->assertCount(2, ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->get());
    }

    private function assertInvariantHolds(Proxy $proxy, SecretPurpose $purpose): void
    {
        foreach ($this->rowsFor($proxy, $purpose) as $row) {
            if ($row['is_current'] !== null) {
                $this->assertNull($row['expires_at'], 'is_current row must have a NULL expires_at');
            } else {
                $this->assertNotNull($row['expires_at'], 'superseded row must have a non-NULL expires_at');
            }
        }
    }

    public function test_invariant_holds_after_every_operation(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'first');
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);

        $this->store->replace($proxy, SecretPurpose::Signing, 'second');
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);

        $this->store->generate($proxy, SecretPurpose::Signing);
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);

        $this->store->generate($proxy, SecretPurpose::Signing);
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);

        $this->store->endOverlap($proxy, SecretPurpose::Signing);
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);

        $this->store->disable($proxy, SecretPurpose::Signing);
        $this->assertInvariantHolds($proxy, SecretPurpose::Signing);
    }

    public function test_during_an_overlap_live_for_returns_both_current_first_then_only_current_after_expiry(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'old-secret');
        $this->store->replace($proxy, SecretPurpose::Signing, 'new-secret');

        $live = $this->store->liveFor($proxy, SecretPurpose::Signing);
        $this->assertSame(['new-secret', 'old-secret'], $live);

        // Push the superseded row's expiry into the past directly — no
        // sweeper, no job, run at all. Liveness is a property of the data.
        ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->whereNull('is_current')
            ->update(['expires_at' => now()->subMinute()]);

        $liveAfterExpiry = $this->store->liveFor($proxy, SecretPurpose::Signing);
        $this->assertSame(['new-secret'], $liveAfterExpiry);
    }

    public function test_a_second_replace_inside_a_running_overlap_discards_the_oldest_immediately(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'oldest');
        $this->store->replace($proxy, SecretPurpose::Signing, 'middle');
        // Middle is now superseded, with a 24h-out expiry — still "running".
        $this->store->replace($proxy, SecretPurpose::Signing, 'newest');

        $values = $this->store->liveFor($proxy, SecretPurpose::Signing);
        $this->assertSame(['newest', 'middle'], $values);
        $this->assertNotContains('oldest', $values);
        $this->assertCount(2, ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->get());
    }

    public function test_end_overlap_is_idempotent(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'old-secret');
        $this->store->replace($proxy, SecretPurpose::Signing, 'new-secret');

        $this->store->endOverlap($proxy, SecretPurpose::Signing);
        $this->assertSame(['new-secret'], $this->store->liveFor($proxy, SecretPurpose::Signing));

        // Calling again with no overlap running is a no-op, no error.
        $this->store->endOverlap($proxy, SecretPurpose::Signing);
        $this->assertSame(['new-secret'], $this->store->liveFor($proxy, SecretPurpose::Signing));

        // Calling when no overlap has ever run for this proxy/purpose is
        // also a no-op, no error.
        $otherProxy = $this->makeProxy();
        $this->store->endOverlap($otherProxy, SecretPurpose::Signing);
        $this->assertSame([], $this->store->liveFor($otherProxy, SecretPurpose::Signing));
    }

    public function test_a_row_that_cannot_be_decrypted_throws_rather_than_being_silently_excluded(): void
    {
        $proxy = $this->makeProxy();

        $this->store->replace($proxy, SecretPurpose::Signing, 'a-fine-secret');

        DB::table('proxy_secrets')
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing->value)
            ->update(['value' => 'not-valid-ciphertext']);

        $this->expectException(SecretUnavailableException::class);

        $this->store->liveFor($proxy, SecretPurpose::Signing);
    }

    public function test_disable_deletes_every_row_and_a_subsequent_generate_never_repeats_the_disabled_value(): void
    {
        $proxy = $this->makeProxy();

        $this->store->generate($proxy, SecretPurpose::Signing);
        $this->store->generate($proxy, SecretPurpose::Signing);
        $disabledValues = $this->store->liveFor($proxy, SecretPurpose::Signing);

        $this->store->disable($proxy, SecretPurpose::Signing);

        $this->assertCount(0, ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->get());

        $fresh = $this->store->generate($proxy, SecretPurpose::Signing);

        $this->assertNotContains($fresh, $disabledValues);
    }

    public function test_the_unique_index_is_never_violated_by_rapid_rotation(): void
    {
        $proxy = $this->makeProxy();

        for ($i = 0; $i < 10; $i++) {
            $this->store->replace($proxy, SecretPurpose::Signing, "secret-{$i}");
        }

        $this->assertCount(2, ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', SecretPurpose::Signing)
            ->get());
    }
}
