<?php

namespace App\Services;

use App\Enums\MatchSource;
use App\Models\Proxy;
use App\Support\SensitiveFields;

/**
 * Resolves whether a field name matches the effective sensitive-field list
 * for a proxy — the product default list (`App\Support\SensitiveFields`)
 * union that proxy's own AC13 additions (AC13, AC14; ADR-024 Decision 4).
 * Matching is by normalised name, exact equality only — never a substring,
 * never the value.
 */
class SensitiveFieldMatcher
{
    /**
     * @var array<string, true> normalised default names, for O(1) lookup
     */
    private readonly array $normalisedDefaults;

    /**
     * @var array<string, true> normalised proxy-addition names, for O(1) lookup
     */
    private readonly array $normalisedAdditions;

    public function __construct(Proxy $proxy)
    {
        $this->normalisedDefaults = array_fill_keys(
            array_map(SensitiveFields::normalise(...), SensitiveFields::DEFAULTS),
            true,
        );

        $this->normalisedAdditions = array_fill_keys(
            array_map(SensitiveFields::normalise(...), $proxy->sensitive_fields ?? []),
            true,
        );
    }

    /**
     * Which list, if any, this field name matches. Checking defaults first is
     * the tie-break: a name in both lists reports `Default` (plan-10
     * Technical ruling 2).
     */
    public function matchFor(string $fieldName): ?MatchSource
    {
        $normalised = SensitiveFields::normalise($fieldName);

        if (isset($this->normalisedDefaults[$normalised])) {
            return MatchSource::Default;
        }

        if (isset($this->normalisedAdditions[$normalised])) {
            return MatchSource::Addition;
        }

        return null;
    }
}
