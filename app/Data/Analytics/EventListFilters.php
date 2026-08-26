<?php

namespace App\Data\Analytics;

use App\Enums\AnalyticsWindow;

/**
 * The Events list's active-filter chip descriptors (plan-11 § API "Prop
 * shapes", Technical rulings 8 and 10). `destination`/`outcome` are `null`
 * when the corresponding query parameter could not be resolved — a chip
 * never claims a narrowing the query did not apply. `day` is likewise `null`
 * when the `date` query parameter didn't resolve (Revision A; `Q-11-04`); a
 * resolved `day` is **not** a fourth chip — design-11 Screen 4 fixes the chip
 * row at three, so the frontend (T24) renders a resolved `day` as the value
 * of the existing Window chip, not as a separate chip.
 */
readonly class EventListFilters
{
    /**
     * @param  array{id: int, url: string, httpMethod: string, isDeleted: bool}|null  $destination
     * @param  array{unit: string, label: string}|null  $outcome
     * @param  string|null  $day  ISO `Y-m-d`, or `null` when unresolved.
     */
    public function __construct(
        public AnalyticsWindow $window,
        public ?array $destination,
        public ?array $outcome,
        public ?string $day,
    ) {
        //
    }
}
