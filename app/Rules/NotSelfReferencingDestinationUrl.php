<?php

namespace App\Rules;

use App\Support\IngestHostGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Save-time half of the delivery-loop guard's direct-cycle check
 * (`docs/briefs/delivery-loop-guard.md`): rejects a destination URL whose
 * host equals this service's own ingest host, or whose host is an
 * IP-literal. Applied to `destinations.*.url` on both `StoreProxyRequest`
 * and `UpdateProxyRequest`, alongside the existing `url:https` rule.
 *
 * `DeliverToDestination::send()` carries the send-time backstop for rows
 * saved before this rule existed and for an `INGEST_URL` change since save
 * — see that method's docblock.
 */
class NotSelfReferencingDestinationUrl implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $host = IngestHostGuard::hostFrom($value);

        if ($host === null) {
            return;
        }

        if (IngestHostGuard::isIpLiteral($host)) {
            $fail(__('The :attribute must not use an IP address as its host.'));

            return;
        }

        if (IngestHostGuard::pointsBackToIngest($host)) {
            $fail(__('The :attribute must not point back at this service.'));
        }
    }
}
