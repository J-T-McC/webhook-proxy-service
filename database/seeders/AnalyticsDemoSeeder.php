<?php

namespace Database\Seeders;

use App\Enums\AttemptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\DispatchKind;
use App\Enums\HttpMethod;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Standalone, Owner-run fixture seeder for item #11's analytics surfaces
 * (Dashboard, Proxy Show "Analytics"/"Destinations" cards, Events list
 * drill-through). This is a chore fixture, not a roadmap task — it is not
 * referenced by `docs/tasks/analytics-tasks.md` and is deliberately NOT
 * wired into `DatabaseSeeder::run()`, so it never fires as a side effect of
 * anyone else's seeding or of the test suite.
 *
 * Run with:
 *   ./vendor/bin/sail artisan db:seed --class=AnalyticsDemoSeeder
 *
 * --- Idempotency: NOT idempotent, and that is the point ---
 * Every run creates a brand-new team, two users, and a handful of proxies /
 * destinations, all named with a run-unique timestamp suffix. Nothing here
 * updates or truncates a prior run's rows, so running this seeder five times
 * in a row is always safe — it simply leaves five independent demo teams
 * behind, never touching another agent's or a prior run's fixtures. The
 * team slug, login email, and page URLs to look at are printed at the end
 * of every run.
 *
 * --- Why the timestamps are set the way they are ---
 * Every analytics figure in `App\Services\DeliveryStatistics` windows on
 * `deliveries.updated_at` / `delivery_attempts.updated_at` — NEVER
 * `created_at`, never `received_at` (plan-11's window-anchor ruling, pinned
 * by `tests/Feature/Analytics/AnchorInvariantTest.php`). Eloquent overwrites
 * `updated_at` to "now" on every `save()` (and `create()`), so a naive
 * `Model::factory()->create([...])` followed by a `created_at` backdate
 * would leave `updated_at` at the moment this seeder ran — every row would
 * land in the same instant, the 24h/7d/30d windows would all read
 * identically, and the daily trend would collapse to a single point.
 *
 * To avoid that trap, `deliveries` and `delivery_attempts` rows are written
 * with `DB::table(...)->insert()` / `insertGetId()` directly, bypassing
 * Eloquent (and its timestamp auto-touch) entirely, with `created_at`/
 * `updated_at` set explicitly to the moment each row reached its terminal
 * state. This matches the real invariant this app relies on: a terminal
 * row's `updated_at` is the settlement time and is never rewritten again
 * after that.
 */
class AnalyticsDemoSeeder extends Seeder
{
    /**
     * Rolling counter used to cycle destinations, delivery kind (live vs
     * replay) and settlement shape deterministically across every proxy
     * this seeder populates, so the mix stays varied without per-call
     * bookkeeping at each call site.
     */
    private int $sequence = 0;

    public function run(): void
    {
        $suffix = CarbonImmutable::now()->format('Ymd-His');

        [$teamA, $ownerA] = $this->makeTeam("Analytics Demo — {$suffix}", "analytics-demo-owner-{$suffix}@example.com");
        [$teamB, $ownerB] = $this->makeTeam("Analytics Demo Second Team — {$suffix}", "analytics-demo-teamb-{$suffix}@example.com");

        $attemptRows = [];

        // --- Proxy 1: "Payments Webhook" — the main showcase proxy. ---
        // Three destinations: two live, one (`legacy`) confined to the older
        // half of the window and then soft-deleted, so its rows exercise the
        // "Deleted" destination-row treatment while still carrying real
        // historical figures (Screen 3, AC6).
        $paymentsProxy = Proxy::factory()->create([
            'team_id' => $teamA->id,
            'name' => 'Payments Webhook',
        ]);
        $primaryDestination = Destination::factory()->create([
            'proxy_id' => $paymentsProxy->id,
            'team_id' => $teamA->id,
            'url' => 'https://api.example.com/hooks/payments',
            'http_method' => HttpMethod::Post,
        ]);
        $billingDestination = Destination::factory()->create([
            'proxy_id' => $paymentsProxy->id,
            'team_id' => $teamA->id,
            'url' => 'https://billing.example.com/hooks/incoming',
            'http_method' => HttpMethod::Put,
        ]);
        $legacyDestination = Destination::factory()->create([
            'proxy_id' => $paymentsProxy->id,
            'team_id' => $teamA->id,
            'url' => 'https://legacy.example.com/hooks/old',
            'http_method' => HttpMethod::Post,
        ]);

        // 30-day window, every day except offset 15 ("15 days ago") — the
        // deliberate zero-traffic gap in the middle of the trend series
        // (AC16 densification must show a real zero-count day, not a hole).
        $thirtyDayOffsets = array_values(array_diff(range(0, 29), [15]));

        $attemptRows = array_merge($attemptRows, $this->seedProxyDeliveries(
            $teamA,
            $paymentsProxy,
            [
                ['destination' => $primaryDestination, 'minOffset' => 0],
                ['destination' => $billingDestination, 'minOffset' => 0],
                // `legacy` only receives traffic on the older half of the
                // window (10-29 days ago) — traffic that predates its
                // soft-delete below, matching how a real destination would
                // accumulate history before being removed.
                ['destination' => $legacyDestination, 'minOffset' => 10],
            ],
            $thirtyDayOffsets,
            perDay: 2,
        ));

        $legacyDestination->delete();

        // --- Proxy 2: "Quiet Integration" — zero traffic, ever. ---
        // Exercises the Dashboard "Proxies" table's "No deliveries yet" row
        // and the Proxy Show Analytics card's collapsed empty state.
        $quietProxy = Proxy::factory()->create([
            'team_id' => $teamA->id,
            'name' => 'Quiet Integration',
        ]);
        Destination::factory()->create([
            'proxy_id' => $quietProxy->id,
            'team_id' => $teamA->id,
            'url' => 'https://quiet.example.com/hook',
            'http_method' => HttpMethod::Post,
        ]);

        // --- Proxy 3: "Retired Webhook" — soft-deleted, with history. ---
        // Traffic is seeded first, then the proxy itself is soft-deleted, so
        // its Dashboard "Proxies" row and figures exercise the "Deleted"
        // proxy-row treatment (AC6) with real historical numbers behind it.
        $retiredProxy = Proxy::factory()->create([
            'team_id' => $teamA->id,
            'name' => 'Retired Webhook',
        ]);
        $retiredDestination = Destination::factory()->create([
            'proxy_id' => $retiredProxy->id,
            'team_id' => $teamA->id,
            'url' => 'https://retired.example.com/hook',
            'http_method' => HttpMethod::Post,
        ]);

        $attemptRows = array_merge($attemptRows, $this->seedProxyDeliveries(
            $teamA,
            $retiredProxy,
            [['destination' => $retiredDestination, 'minOffset' => 0]],
            [3, 6, 9, 12, 15, 18, 21, 24],
            perDay: 1,
        ));

        $retiredProxy->delete();

        // --- Team B: proves team scoping — its figures must never leak
        // into Team A's Dashboard, proxy list, or per-proxy Analytics. ---
        $teamBProxy = Proxy::factory()->create([
            'team_id' => $teamB->id,
            'name' => 'Team B Notifications',
        ]);
        $teamBDestination = Destination::factory()->create([
            'proxy_id' => $teamBProxy->id,
            'team_id' => $teamB->id,
            'url' => 'https://teamb.example.com/hook',
            'http_method' => HttpMethod::Post,
        ]);

        $attemptRows = array_merge($attemptRows, $this->seedProxyDeliveries(
            $teamB,
            $teamBProxy,
            [['destination' => $teamBDestination, 'minOffset' => 0]],
            range(0, 9),
            perDay: 1,
        ));

        // One bulk insert for every attempt row collected above, across all
        // three proxies — each row already carries its own explicit
        // `created_at`/`updated_at`, so this single statement never touches
        // Eloquent's timestamp machinery.
        foreach (array_chunk($attemptRows, 200) as $chunk) {
            DB::table('delivery_attempts')->insert($chunk);
        }

        $this->printSummary($teamA, $ownerA, $paymentsProxy, $quietProxy, $retiredProxy, $teamB, $ownerB, $teamBProxy);
    }

    /**
     * Create an owner user (the factory's own `afterCreating` hook gives
     * every new user a personal team and switches them into it) and rename
     * that auto-created personal team to the given demo name — `Team`'s own
     * `updating` hook regenerates a unique slug from the new name, so no
     * manual membership/slug bookkeeping is needed here.
     *
     * @return array{0: Team, 1: User}
     */
    private function makeTeam(string $teamName, string $ownerEmail): array
    {
        $owner = User::factory()->create([
            'name' => 'Analytics Demo Owner',
            'email' => $ownerEmail,
        ]);

        $team = $owner->currentTeam;
        $team->update(['name' => $teamName]);

        return [$team->fresh(), $owner];
    }

    /**
     * Create deliveries and their attempts for one proxy across a set of
     * "days ago" offsets, split across the given destinations, cycling
     * through four settlement shapes so every figure this proxy contributes
     * to has real data behind it:
     *
     * - `$sequence % 6` in {0,1,2}: clean success — one succeeded attempt.
     *   No retry involved; contributes to the base success count at both
     *   grains identically.
     * - `$sequence % 6 === 3`: success after one retry — attempt 1 fails,
     *   attempt 2 succeeds. Delivery-level: succeeded. Attempt-level: one
     *   success, one failure. This (and the next case) is the source of the
     *   both-unit divergence the feature exists to show, and of "eventual
     *   success" / retry volume (AC19).
     * - `$sequence % 6 === 4`: success after two retries — two failed
     *   attempts then a success. Same shape, more retry volume.
     * - `$sequence % 6 === 5`: terminal failure — three failed attempts,
     *   delivery never succeeds. Feeds "Terminal failures" (both the table
     *   column and the Retry & replay tile) with real, drill-through-able
     *   rows.
     *
     * Every ~9th delivery (`$sequence % 9 === 0`) is a replay (`kind`), so
     * the live-vs-replay split is never all-live. Roughly 1 in 10 resolved
     * attempts gets an outlier duration (2.5-7s vs. the normal 40-380ms),
     * which is what makes the average and the 95th percentile read as
     * genuinely different numbers rather than coincidentally equal.
     *
     * @param  list<array{destination: Destination, minOffset: int}>  $destinations  each destination and the largest "days ago" value it stops being eligible below — lets one destination's traffic be confined to the older part of the window, ahead of a later soft-delete
     * @param  list<int>  $dayOffsets  "days ago" values (0 = today) to place deliveries on
     * @return list<array<string, mixed>> `delivery_attempts` rows, NOT yet inserted — the caller batches every proxy's rows into one final bulk insert
     */
    private function seedProxyDeliveries(Team $team, Proxy $proxy, array $destinations, array $dayOffsets, int $perDay): array
    {
        $attemptRows = [];

        foreach ($dayOffsets as $offset) {
            $eligible = array_values(array_filter($destinations, fn (array $d) => $offset >= $d['minOffset']));
            $dayTimestamp = $this->dayTimestamp($offset);

            for ($n = 0; $n < $perDay; $n++) {
                $destination = $eligible[$this->sequence % count($eligible)]['destination'];
                $kind = $this->sequence % 9 === 0 ? DispatchKind::Replay : DispatchKind::Original;
                [$deliveryStatus, $attemptPlan] = $this->settlementPlan($this->sequence % 6);

                $event = WebhookEvent::factory()->create([
                    'proxy_id' => $proxy->id,
                    'team_id' => $team->id,
                    'received_at' => $dayTimestamp,
                ]);

                $deliveryId = DB::table('deliveries')->insertGetId([
                    'team_id' => $team->id,
                    'proxy_id' => $proxy->id,
                    'destination_id' => $destination->id,
                    'webhook_event_id' => $event->id,
                    'dispatch_uuid' => (string) Str::uuid(),
                    'kind' => $kind->value,
                    'status' => $deliveryStatus->value,
                    'next_attempt_at' => null,
                    'created_at' => $dayTimestamp,
                    'updated_at' => $dayTimestamp,
                ]);

                $attemptCount = count($attemptPlan);

                foreach ($attemptPlan as $i => $status) {
                    // Earlier attempts in the same delivery settle a few
                    // minutes before the final one, still on the same day —
                    // the delivery's own `updated_at` above is the final
                    // attempt's settlement time.
                    $attemptTimestamp = $dayTimestamp->subMinutes(($attemptCount - 1 - $i) * 3);
                    $isOutlier = random_int(1, 100) <= 10;

                    $attemptRows[] = [
                        'delivery_id' => $deliveryId,
                        'team_id' => $team->id,
                        'proxy_id' => $proxy->id,
                        'destination_id' => $destination->id,
                        'ingest_id' => (string) Str::uuid(),
                        'status' => $status->value,
                        'http_status' => $status === AttemptStatus::Succeeded ? 200 : 500,
                        'error_summary' => $status === AttemptStatus::Failed ? 'HTTP 500' : null,
                        'attempt_number' => $i + 1,
                        'started_at' => $attemptTimestamp->subSeconds(random_int(1, 5)),
                        'duration_ms' => $isOutlier ? random_int(2500, 7000) : random_int(40, 380),
                        'created_at' => $attemptTimestamp,
                        'updated_at' => $attemptTimestamp,
                    ];
                }

                $this->sequence++;
            }
        }

        return $attemptRows;
    }

    /**
     * The delivery-level outcome plus the ordered attempt-level outcomes for
     * one of the four settlement shapes described on `seedProxyDeliveries()`.
     *
     * @return array{0: DeliveryStatus, 1: list<AttemptStatus>}
     */
    private function settlementPlan(int $shape): array
    {
        return match (true) {
            $shape <= 2 => [DeliveryStatus::Succeeded, [AttemptStatus::Succeeded]],
            $shape === 3 => [DeliveryStatus::Succeeded, [AttemptStatus::Failed, AttemptStatus::Succeeded]],
            $shape === 4 => [DeliveryStatus::Succeeded, [AttemptStatus::Failed, AttemptStatus::Failed, AttemptStatus::Succeeded]],
            default => [DeliveryStatus::Failed, [AttemptStatus::Failed, AttemptStatus::Failed, AttemptStatus::Failed]],
        };
    }

    /**
     * The settlement timestamp for a given "days ago" offset — used as both
     * `created_at` and `updated_at` on the delivery this day's rows belong
     * to (see class docblock). Offset 0 ("today") is anchored to a random
     * point within the last few hours rather than a random hour-of-day, so
     * it can never land in the future relative to when this seeder runs.
     */
    private function dayTimestamp(int $offset): CarbonImmutable
    {
        if ($offset === 0) {
            return CarbonImmutable::now()->subMinutes(random_int(30, 240));
        }

        return CarbonImmutable::now()->startOfDay()->subDays($offset)
            ->addHours(random_int(1, 22))
            ->addMinutes(random_int(0, 59));
    }

    private function printSummary(
        Team $teamA,
        User $ownerA,
        Proxy $paymentsProxy,
        Proxy $quietProxy,
        Proxy $retiredProxy,
        Team $teamB,
        User $ownerB,
        Proxy $teamBProxy,
    ): void {
        $this->command->info('Analytics demo data seeded.');
        $this->command->line('');
        $this->command->line("Team A: {$teamA->name} (slug: {$teamA->slug})");
        $this->command->line("  Login: {$ownerA->email} / password: password");
        $this->command->line("  Dashboard:        /{$teamA->slug}/dashboard");
        $this->command->line("  Payments Webhook: /{$teamA->slug}/proxies/{$paymentsProxy->id} (main showcase: divergence, retries, terminal failures, latency spread, deleted destination)");
        $this->command->line("  Quiet Integration: /{$teamA->slug}/proxies/{$quietProxy->id} (zero traffic)");
        $this->command->line("  Retired Webhook:  /{$teamA->slug}/proxies/{$retiredProxy->id} (soft-deleted proxy, id {$retiredProxy->id} — visible as a Deleted row on the Dashboard's Proxies table, not directly navigable once trashed)");
        $this->command->line('');
        $this->command->line("Team B: {$teamB->name} (slug: {$teamB->slug})");
        $this->command->line("  Login: {$ownerB->email} / password: password");
        $this->command->line("  Dashboard:        /{$teamB->slug}/dashboard");
        $this->command->line("  Team B Notifications: /{$teamB->slug}/proxies/{$teamBProxy->id}");
    }
}
