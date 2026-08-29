<?php

namespace App\Actions;

use App\Models\ProxySecret;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The liveness net for a lost or dropped {@see ExpireProxySecrets} delayed
 * job (R10; ADR-015 Decision 5's shape, reused). Registered as the
 * `secrets:purge-expired` console command (scheduled daily,
 * `routes/console.php`), mirroring `payloads:purge-expired`
 * ({@see PurgeExpiredPayloads}).
 *
 * Scans every proxy and purpose for a superseded row whose overlap has
 * passed and deletes it — a single unscoped `DELETE`, since liveness is
 * already a property of `expires_at` and this sweep only needs to catch up
 * on erasure, never on correctness. Cannot extend a window, only shorten
 * one that has already ended.
 */
class PurgeExpiredProxySecrets
{
    use AsAction;

    public string $commandSignature = 'secrets:purge-expired';

    public function handle(): void
    {
        ProxySecret::query()
            ->whereNull('is_current')
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
