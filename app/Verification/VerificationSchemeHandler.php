<?php

namespace App\Verification;

use App\Models\Proxy;
use Illuminate\Http\Request;

/**
 * One implementation per `App\Enums\VerificationScheme` case (AC51, AC52,
 * AC53). Pure with respect to persistence — the live secret set is passed
 * in rather than fetched, so a handler never touches `proxy_secrets`
 * directly (plan-10 Technical ruling 14/binding constraint 5).
 */
interface VerificationSchemeHandler
{
    /**
     * @param  list<string>  $liveSecrets
     */
    public function verify(Proxy $proxy, Request $request, string $rawBody, array $liveSecrets): bool;
}
