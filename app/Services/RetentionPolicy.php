<?php

namespace App\Services;

use App\Models\Team;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The single source of the retention window (AC1-AC3, ADR-012 Decision 2).
 * `windowFor(Team)` is the only method a later per-team/per-plan lever (V5) or
 * region dimension (V6) would ever change; `cutoffFor`/`expiresAt` derive from
 * it rather than re-reading `config('retention.days')` themselves — no other
 * place in the codebase may read that key directly or hard-code a day count
 * (AC3).
 *
 * This is also the single point that enforces plan-05 §Validation's *Config
 * sanity* invariant for `retention.days`: a resolved window of zero or less
 * would make every captured payload immediately collectable for permanent
 * erasure (review-05 finding 1(a)), so a non-positive value fails loudly
 * here rather than silently falling back to a default.
 */
class RetentionPolicy
{
    /**
     * The retention window for a team. Today the same fixed window for every
     * team; `$team` is the V5/V6 extension point.
     *
     * @throws RuntimeException if `retention.days` does not resolve to a
     *                          positive integer (blank, non-numeric, zero, or negative env values
     *                          all cast to a value caught here).
     */
    public function windowFor(Team $team): CarbonInterval
    {
        $days = (int) config('retention.days');

        if ($days < 1) {
            throw new RuntimeException(sprintf(
                "config('retention.days') must resolve to a positive integer; got %d. Refusing to ".
                'silently substitute a default — a retention window of zero or less would make '.
                'every captured payload immediately collectable for permanent erasure.',
                $days,
            ));
        }

        return CarbonInterval::days($days);
    }

    /**
     * The GC scan bound: the point in time before which a team's captured
     * events have exceeded their retention window.
     */
    public function cutoffFor(Team $team): CarbonImmutable
    {
        return CarbonImmutable::now()->sub($this->windowFor($team));
    }

    /**
     * The per-event answer: when a captured event's payload expires.
     *
     * `WebhookEvent` carries no `team()` relation (it is raw-only, ADR-010), so
     * the owning team is resolved by id rather than by adding a relation this
     * plan does not authorize.
     */
    public function expiresAt(WebhookEvent $event): CarbonImmutable
    {
        /** @var Carbon $createdAt */
        $createdAt = $event->created_at;

        $team = Team::query()->withTrashed()->findOrFail($event->team_id);

        return CarbonImmutable::parse($createdAt)->add($this->windowFor($team));
    }
}
