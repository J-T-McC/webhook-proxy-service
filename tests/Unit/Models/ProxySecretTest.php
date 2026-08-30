<?php

namespace Tests\Unit\Models;

use App\Enums\SecretPurpose;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use Illuminate\Database\QueryException;
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

    /**
     * The rotation invariant: at most one current secret per proxy per purpose,
     * enforced by the `proxy_secrets_proxy_id_purpose_is_current_unique` index.
     * `is_current` is null rather than false on superseded rows precisely so that
     * MySQL's unique index ignores them, which is what lets an overlap window hold
     * several superseded secrets at once.
     */
    public function test_a_second_current_row_for_the_same_proxy_and_purpose_is_rejected(): void
    {
        $proxy = $this->makeProxy();

        DB::table('proxy_secrets')->insert([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => 'signing',
            'value' => 'ciphertext-a',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('proxy_secrets')->insert([
            'team_id' => $proxy->team_id,
            'proxy_id' => $proxy->id,
            'purpose' => 'signing',
            'value' => 'ciphertext-b',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_any_number_of_superseded_rows_with_null_is_current_are_allowed(): void
    {
        $proxy = $this->makeProxy();

        for ($i = 0; $i < 3; $i++) {
            DB::table('proxy_secrets')->insert([
                'team_id' => $proxy->team_id,
                'proxy_id' => $proxy->id,
                'purpose' => 'signing',
                'value' => "ciphertext-{$i}",
                'is_current' => null,
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(3, DB::table('proxy_secrets')->where('proxy_id', $proxy->id)->count());
    }
}
