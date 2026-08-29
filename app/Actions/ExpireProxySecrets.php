<?php

namespace App\Actions;

use App\Models\ProxySecret;
use Lorisleiva\Actions\Concerns\AsJob;

/**
 * Deletes a superseded `proxy_secrets` row once its overlap window has
 * passed (ADR-021 Decision 3's "erasure still happens promptly"). Dispatched
 * delayed by `RotationOverlap::HOURS` from `SecretStore::replace()`/
 * `generate()` at the moment a row is superseded.
 *
 * Scalar arguments only (`proxyId: int, purpose: string`) — ADR-021
 * Decision 8: a `Proxy`/`ProxySecret` model argument would silently
 * re-enable `SerializesModels` via `JobDecorator`, carrying the model (and,
 * for a `ProxySecret`, its secret) through the queue.
 *
 * Guarded on `expires_at <= now()`, so it is a no-op if the row's window has
 * not yet passed (e.g. a further rotation already restarted the window) or
 * if the row no longer exists (already deleted by a further rotation or by
 * the `secrets:purge-expired` sweeper). Neither this job nor the sweeper can
 * extend a window — both only delete, and only a row already past its own
 * expiry.
 */
class ExpireProxySecrets
{
    use AsJob;

    public int $tries = 1;

    public function handle(int $proxyId, string $purpose): void
    {
        ProxySecret::query()
            ->where('proxy_id', $proxyId)
            ->where('purpose', $purpose)
            ->whereNull('is_current')
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
