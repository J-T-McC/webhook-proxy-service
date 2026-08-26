<?php

namespace App\Data\Analytics;

use App\Enums\AnalyticsWindow;

/**
 * The Events list's active-filter chip descriptors (plan-11 § API "Prop
 * shapes", Technical ruling 8). `destination`/`outcome` are `null` when the
 * corresponding query parameter could not be resolved — a chip never claims
 * a narrowing the query did not apply.
 */
readonly class EventListFilters
{
    /**
     * @param  array{id: int, url: string, httpMethod: string, isDeleted: bool}|null  $destination
     * @param  array{unit: string, label: string}|null  $outcome
     */
    public function __construct(
        public AnalyticsWindow $window,
        public ?array $destination,
        public ?array $outcome,
    ) {
        //
    }
}
