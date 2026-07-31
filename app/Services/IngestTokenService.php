<?php

namespace App\Services;

use App\Models\Proxy;

/**
 * Mints, hashes and stores a proxy's ingest token (ADR-006).
 *
 * The plaintext token is a 256-bit CSPRNG value stored encrypted at rest; the
 * SHA-256 hash (BINARY(32)) is the O(1) inbound lookup key. The plaintext token
 * is never logged.
 */
class IngestTokenService
{
    /**
     * Generate a URL-safe 256-bit (32-byte) CSPRNG token (base64url, unpadded).
     */
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * SHA-256 of the plaintext token as raw BINARY(32).
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token, binary: true);
    }

    /**
     * Assign a freshly minted, collision-free token (plaintext + hash) to a proxy
     * in memory. The caller persists the proxy; the DB UNIQUE index is the ultimate
     * guarantee, and this regenerates on the (astronomically unlikely) collision.
     */
    public function assignTo(Proxy $proxy): Proxy
    {
        $token = $this->uniqueToken();

        $proxy->ingest_token = $token;
        $proxy->ingest_token_hash = $this->hash($token);

        return $proxy;
    }

    /**
     * Rotate a proxy's ingest token and persist it. No UI exists at item #1.
     */
    public function rotate(Proxy $proxy): Proxy
    {
        $this->assignTo($proxy)->save();

        return $proxy;
    }

    /**
     * Produce a token whose hash does not already exist across all teams and
     * including soft-deleted proxies (the UNIQUE index spans both).
     */
    protected function uniqueToken(): string
    {
        do {
            $token = $this->generate();
            $hash = $this->hash($token);
        } while ($this->hashExists($hash));

        return $token;
    }

    /**
     * Whether a proxy already holds the given token hash (any team, incl. trashed).
     *
     * Team scoping is not a global scope, so this query already spans all teams;
     * `withTrashed()` additionally spans soft-deleted proxies (the UNIQUE index
     * covers both).
     */
    protected function hashExists(string $hash): bool
    {
        return Proxy::withTrashed()
            ->where('ingest_token_hash', $hash)
            ->exists();
    }
}
