<?php

namespace Tests\Unit\Models;

use App\Enums\SecretPurpose;
use App\Models\Destination;
use App\Models\DispatchedPayload;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Models\Team;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins two guarantees that must survive every later task in this feature
 * untouched (plan-10 § Test strategy "Encryption at rest and the closed store
 * set"): the `APP_PREVIOUS_KEYS` column list ADR-021 § Impact enumerates
 * matches the casts actually declared, and a secret write never puts plaintext
 * into the query log.
 */
class EncryptedColumnSurfaceTest extends TestCase
{
    /**
     * @return array<string, string> "table.column" => cast string, for every
     *                               `encrypted`-family cast declared on the model
     */
    private function encryptedCastsFor(string $modelClass): array
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();

        $casts = array_filter(
            $model->getCasts(),
            fn (string $cast): bool => str_starts_with($cast, 'encrypted'),
        );

        return collect($casts)
            ->mapWithKeys(fn (string $cast, string $column) => ["{$table}.{$column}" => $cast])
            ->all();
    }

    public function test_the_app_previous_keys_column_list_matches_the_casts_actually_declared(): void
    {
        $encrypted = array_merge(
            $this->encryptedCastsFor(ProxySecret::class),
            $this->encryptedCastsFor(Proxy::class),
            $this->encryptedCastsFor(Destination::class),
            $this->encryptedCastsFor(WebhookEvent::class),
            $this->encryptedCastsFor(DispatchedPayload::class),
        );

        // ADR-021 § Impact: six columns across five models, matching the surface
        // ADR-010 Amendment B's "never drop a prior key" rule already covered plus
        // the two this feature adds.
        $this->assertEqualsCanonicalizing([
            'webhook_events.body',
            'webhook_events.headers',
            'dispatched_payloads.body',
            'proxies.ingest_token',
            'proxy_secrets.value',
            'destinations.credential_secret',
        ], array_keys($encrypted));
    }

    public function test_a_proxy_secret_write_produces_no_plaintext_secret_in_the_query_log(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);

        $plaintext = 'do-not-log-me-'.bin2hex(random_bytes(16));

        $bindingsSeen = [];
        DB::listen(function (QueryExecuted $query) use (&$bindingsSeen): void {
            if (str_contains($query->sql, 'proxy_secrets')) {
                $bindingsSeen[] = $query->bindings;
            }
        });

        (new ProxySecret([
            'team_id' => $team->id,
            'proxy_id' => $proxy->id,
            'purpose' => SecretPurpose::Signing,
            'value' => $plaintext,
            'is_current' => true,
        ]))->save();

        $this->assertNotEmpty($bindingsSeen, 'Expected at least one proxy_secrets query to be logged.');

        foreach ($bindingsSeen as $bindings) {
            foreach ($bindings as $binding) {
                $this->assertStringNotContainsString(
                    $plaintext,
                    is_string($binding) ? $binding : (string) $binding,
                );
            }
        }
    }

    public function test_a_destination_credential_write_produces_no_plaintext_secret_in_the_query_log(): void
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id]);
        $destination = Destination::factory()->createQuietly(['proxy_id' => $proxy->id, 'team_id' => $team->id]);

        $plaintext = 'do-not-log-me-either-'.bin2hex(random_bytes(16));

        $bindingsSeen = [];
        DB::listen(function (QueryExecuted $query) use (&$bindingsSeen): void {
            if (str_contains($query->sql, 'destinations')) {
                $bindingsSeen[] = $query->bindings;
            }
        });

        $destination->credential_secret = $plaintext;
        $destination->save();

        $this->assertNotEmpty($bindingsSeen, 'Expected at least one destinations query to be logged.');

        foreach ($bindingsSeen as $bindings) {
            foreach ($bindings as $binding) {
                $this->assertStringNotContainsString(
                    $plaintext,
                    is_string($binding) ? $binding : (string) $binding,
                );
            }
        }
    }
}
