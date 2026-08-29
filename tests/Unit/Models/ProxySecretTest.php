<?php

namespace Tests\Unit\Models;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProxySecretTest extends TestCase
{
    private function makeProxy(): Proxy
    {
        $team = Team::factory()->createQuietly();

        return Proxy::factory()->createQuietly(['team_id' => $team->id]);
    }

    public function test_value_round_trips_through_the_encrypted_cast_and_is_not_plaintext_at_rest(): void
    {
        $proxy = $this->makeProxy();

        $secret = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'plaintext-secret',
            'is_current' => true,
        ]);
        $secret->save();

        $this->assertSame('plaintext-secret', $secret->fresh()->value);

        $raw = DB::table('proxy_secrets')->where('id', $secret->id)->value('value');
        $this->assertNotSame('plaintext-secret', $raw);
    }

    public function test_value_is_hidden_from_to_array_and_to_json(): void
    {
        $proxy = $this->makeProxy();

        $secret = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'plaintext-secret',
            'is_current' => true,
        ]);
        $secret->save();
        $secret = $secret->fresh();

        $this->assertArrayNotHasKey('value', $secret->toArray());
        $this->assertStringNotContainsString('value', $secret->toJson());
        $this->assertStringNotContainsString('plaintext-secret', $secret->toJson());
    }

    public function test_proxy_secrets_relation_resolves(): void
    {
        $proxy = $this->makeProxy();

        (new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'plaintext-secret',
            'is_current' => true,
        ]))->save();

        $this->assertCount(1, $proxy->fresh()->secrets);
        $this->assertInstanceOf(ProxySecret::class, $proxy->fresh()->secrets->first());
    }

    public function test_live_scope_includes_current_and_not_yet_expired_but_excludes_expired(): void
    {
        $proxy = $this->makeProxy();

        $current = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'current-value',
            'is_current' => true,
        ]);
        $current->save();

        $supersededNotExpired = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'superseded-not-expired-value',
            'is_current' => null,
            'expires_at' => now()->addHours(12),
        ]);
        $supersededNotExpired->save();

        $supersededExpired = new ProxySecret([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => 'superseded-expired-value',
            'is_current' => null,
            'expires_at' => now()->subHour(),
        ]);
        $supersededExpired->save();

        $liveIds = ProxySecret::live()->pluck('id')->all();

        $this->assertContains($current->id, $liveIds);
        $this->assertContains($supersededNotExpired->id, $liveIds);
        $this->assertNotContains($supersededExpired->id, $liveIds);
    }
}
