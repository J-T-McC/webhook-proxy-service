<?php

namespace App\Services;

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
 * directly — `InboundVerifier`, the signing endpoints and the delivery
 * resolver all go through this service.
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
     * two-row cap (AC29) is never briefly exceeded. The demoted row's
     * prompt erasure (the delayed job plus the daily sweeper) is wired in
     * at T15, once `App\Actions\ExpireProxySecrets` exists; until then the
     * live-set predicate on `expires_at` is what makes the demoted row stop
     * being honoured at the right instant regardless (ADR-021 Decision 3 —
     * "expiry needs no mechanism to be correct").
     */
    public function replace(Proxy $proxy, SecretPurpose $purpose, string $newValue): void
    {
        DB::transaction(function () use ($proxy, $purpose, $newValue): void {
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
        });
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
     * superseded (ADR-021 Decision 5 — used for signing; verification's
     * "not required" leaves its dormant secret alone and never calls this).
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
