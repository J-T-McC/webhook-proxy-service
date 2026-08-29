<?php

namespace App\Services;

use App\Actions\ExpireProxySecrets;
use App\Data\SecretStatus;
use App\Enums\SecretPurpose;
use App\Exceptions\SecretUnavailableException;
use App\Models\Proxy;
use App\Models\ProxySecret;
use App\Support\RotationOverlap;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * **The single reader and writer of `proxy_secrets`** (plan-10 Technical
 * ruling 14, ADR-021 Decision 3). No other class queries this table
 * directly — the signing endpoints and the delivery resolver go through
 * this service. `InboundVerifier` once did too; ADR-026 Decision B removed
 * it, and every other class, from the product in full.
 *
 * `replace()` is the whole of AC29 in one operation: it deletes any
 * already-superseded row before demoting the current one to a 24-hour
 * overlap and inserting the new current row, so at most two rows ever exist
 * for a `(proxy, purpose)` at any instant — the cap is a write-path
 * property, not a schema constraint (ADR-021 Decision 4). The
 * `is_current IS NOT NULL` ⟺ `expires_at IS NULL` invariant holds after
 * every operation this class performs.
 */
class SecretStore
{
    /**
     * The live set for a purpose — current first, non-expired
     * (`ProxySecret::live()`, T2). Throws {@see SecretUnavailableException}
     * rather than excluding a row that fails to decrypt: a partial list
     * would be indistinguishable from a completed rotation (AC11).
     *
     * @return list<string>
     */
    public function liveFor(Proxy $proxy, SecretPurpose $purpose): array
    {
        $values = ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->live()
            ->get()
            ->map(fn (ProxySecret $secret): string => $this->decrypt($secret, $purpose))
            ->all();

        return array_values($values);
    }

    /**
     * Rotate the secret: delete any already-superseded row, demote the
     * current row (if any) into a {@see RotationOverlap::HOURS}-hour
     * overlap, then insert the new current row — one transaction, so the
     * two-row cap (AC29) is never briefly exceeded. Schedules
     * {@see ExpireProxySecrets} to erase the demoted row once its overlap
     * passes (R10); the job's own guard, and the fact that the live-set
     * predicate excludes an expired row on its own regardless
     * (ADR-021 Decision 3), make early, late or lost execution harmless.
     */
    public function replace(Proxy $proxy, SecretPurpose $purpose, string $newValue): void
    {
        $hadCurrent = DB::transaction(function () use ($proxy, $purpose, $newValue): bool {
            ProxySecret::query()
                ->where('proxy_id', $proxy->id)
                ->where('purpose', $purpose)
                ->whereNull('is_current')
                ->delete();

            $current = ProxySecret::query()
                ->where('proxy_id', $proxy->id)
                ->where('purpose', $purpose)
                ->where('is_current', true)
                ->first();

            if ($current !== null) {
                $current->update([
                    'is_current' => null,
                    'expires_at' => now()->addHours(RotationOverlap::HOURS),
                ]);
            }

            ProxySecret::create([
                'team_id' => $proxy->team_id,
                'proxy_id' => $proxy->id,
                'purpose' => $purpose,
                'value' => $newValue,
                'is_current' => true,
                'expires_at' => null,
            ]);

            return $current !== null;
        });

        if ($hadCurrent) {
            ExpireProxySecrets::dispatch($proxy->id, $purpose->value)
                ->delay(now()->addHours(RotationOverlap::HOURS))
                ->afterCommit();
        }
    }

    /**
     * Non-secret status metadata for a `(proxy, purpose)` slot — `null` when
     * no secret has ever been configured. Never returns a value or a length
     * (AC26, AC57); the only fields are presence, a changed timestamp and a
     * running overlap's expiry. Kept on `SecretStore` rather than a direct
     * `ProxySecret` query anywhere else, so this table still has exactly one
     * reader (plan-10 Technical ruling 14) even for its status surface
     * (`ProxySecurityResource`, T22).
     */
    public function statusFor(Proxy $proxy, SecretPurpose $purpose): ?SecretStatus
    {
        $current = ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->where('is_current', true)
            ->first();

        if ($current === null) {
            return null;
        }

        $overlapping = ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->whereNull('is_current')
            ->where('expires_at', '>', now())
            ->first(['expires_at']);

        return new SecretStatus(
            // `created_at` is nullable only on the model's own docblock
            // (a defensive Eloquent convention); a row this method just
            // selected from the database always has one — `now()` is an
            // unreachable fallback kept only to satisfy the non-nullable
            // DTO field without a suppression.
            changedAt: $current->created_at ?? now(),
            overlapExpiresAt: $overlapping?->expires_at,
        );
    }

    /**
     * Generate a fresh `whsec_`-prefixed base64 signing secret and rotate it
     * in through {@see self::replace()} — the same overlap/cap behaviour as
     * any other rotation (AC56). Re-enabling after {@see self::disable()}
     * always produces a fresh value, never the one previously disabled
     * (ADR-021 Decision 5), because this always generates new random bytes.
     */
    public function generate(Proxy $proxy, SecretPurpose $purpose): string
    {
        $value = 'whsec_'.base64_encode(random_bytes(32));

        $this->replace($proxy, $purpose, $value);

        return $value;
    }

    /**
     * End a running overlap immediately: delete every superseded row for
     * this `(proxy, purpose)`. Idempotent — a no-op when none is running.
     */
    public function endOverlap(Proxy $proxy, SecretPurpose $purpose): void
    {
        ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->whereNull('is_current')
            ->delete();
    }

    /**
     * Disable this purpose entirely: delete every row, current and
     * superseded (ADR-021 Decision 5) — used for disabling signing, the
     * only call site (`ProxySigningController::destroy()`).
     */
    public function disable(Proxy $proxy, SecretPurpose $purpose): void
    {
        ProxySecret::query()
            ->where('proxy_id', $proxy->id)
            ->where('purpose', $purpose)
            ->delete();
    }

    /**
     * Decrypts the raw stored ciphertext directly through `Crypt`, rather
     * than reading `$secret->value` (which would trigger the same decrypt
     * inside Eloquent's `encrypted` cast, but invisibly to static analysis
     * — Larastan cannot see a cast-triggered exception through a plain
     * property access, so the catch below would otherwise be flagged as
     * dead code).
     *
     * @throws SecretUnavailableException if the stored ciphertext cannot be
     *                                    decrypted.
     */
    private function decrypt(ProxySecret $secret, SecretPurpose $purpose): string
    {
        try {
            return Crypt::decryptString((string) $secret->getRawOriginal('value'));
        } catch (DecryptException) {
            throw new SecretUnavailableException($purpose);
        }
    }
}
