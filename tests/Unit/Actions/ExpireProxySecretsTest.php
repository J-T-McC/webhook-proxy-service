<?php

namespace Tests\Unit\Actions;

use App\Actions\ExpireProxySecrets;
use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use Tests\TestCase;

/**
 * T15 — the delayed per-row delete (R10; ADR-021 Decision 3). Scalar
 * arguments only, so `AsJob`-only actions have no `::run()`; the job body is
 * invoked directly via `app(ExpireProxySecrets::class)->handle(...)`.
 */
class ExpireProxySecretsTest extends TestCase
{
    private function makeProxy(): Proxy
    {
        $team = Team::factory()->createQuietly();

        return Proxy::factory()->state(['team_id' => $team->id])->createQuietly();
    }

    private function makeSupersededSecret(Proxy $proxy, \DateTimeInterface|string $expiresAt): ProxySecret
    {
        $secret = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Verification,
            'value' => 'superseded-value',
            'is_current' => null,
            'expires_at' => $expiresAt,
        ]);
        $secret->save();

        return $secret;
    }

    public function test_deletes_a_row_whose_expiry_has_passed(): void
    {
        $proxy = $this->makeProxy();
        $secret = $this->makeSupersededSecret($proxy, now()->subMinute());

        app(ExpireProxySecrets::class)->handle($proxy->id, SecretPurpose::Verification->value);

        $this->assertNull(ProxySecret::query()->find($secret->id));
    }

    public function test_is_a_noop_against_a_row_whose_window_has_not_passed(): void
    {
        $proxy = $this->makeProxy();
        $secret = $this->makeSupersededSecret($proxy, now()->addHours(24));

        app(ExpireProxySecrets::class)->handle($proxy->id, SecretPurpose::Verification->value);

        $this->assertNotNull(ProxySecret::query()->find($secret->id));
    }

    public function test_is_a_noop_if_the_row_no_longer_exists(): void
    {
        $proxy = $this->makeProxy();

        // No row at all for this (proxy, purpose) — a further rotation or the
        // sweeper already removed it.
        app(ExpireProxySecrets::class)->handle($proxy->id, SecretPurpose::Verification->value);

        $this->assertCount(0, ProxySecret::query()->where('proxy_id', $proxy->id)->get());
    }
}
