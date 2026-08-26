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
     * Plausible, distinct names for the randomly-sized fleet of "normal"
     * traffic-carrying proxies this seeder generates alongside the fixed
     * "Payments Webhook" showcase proxy. Large enough that up to nine extra
     * proxies (ten total, minus "Payments Webhook") can be drawn without a
     * repeat.
     */
    private const array PROXY_NAME_POOL = [
        'Order Fulfillment',
        'Inventory Sync',
        'Customer Support Relay',
        'Marketing Automation',
        'Shipping Updates',
        'Subscription Billing',
        'Fraud Alerts',
        'CRM Sync',
        'Support Ticket Relay',
        'Product Reviews',
        'Loyalty Rewards',
        'Warehouse Events',
    ];

    /**
     * Endpoint paths used to build each proxy's distinct destination set —
     * combined with a per-proxy domain slug so every proxy's destinations
     * look like a real integration's rather than numbered clones.
     */
    private const array DESTINATION_PATH_POOL = [
        '/hooks/incoming',
        '/webhooks',
        '/api/inbound',
        '/events',
    ];

    /**
     * Rolling counter used to cycle destinations and delivery kind (live vs
     * replay) deterministically across every proxy this seeder populates, so
     * the mix stays varied without per-call bookkeeping at each call site.
     * Settlement shape is NOT cycled from this counter — see
     * `randomSettlementWeights()` / `settlementPlan()` — because a
     * deterministic cycle can't produce the skewed, realistic proportions a
     * healthy webhook proxy actually shows (heavy first-attempt success,
     * retries and terminal failures as a small minority).
     */
    private int $sequence = 0;

    public function run(): void
    {
        $suffix = CarbonImmutable::now()->format('Ymd-His');

        [$teamA, $ownerA] = $this->makeTeam("Analytics Demo — {$suffix}", "analytics-demo-owner-{$suffix}@example.com");
        [$teamB, $ownerB] = $this->makeTeam("Analytics Demo Second Team — {$suffix}", "analytics-demo-teamb-{$suffix}@example.com");

        $attemptRows = [];

        // --- The randomly-sized "normal traffic" fleet (1-10 proxies). ---
        // "Payments Webhook" is always present as the main showcase proxy —
        // three destinations: two live, one (`legacy`) confined to the older
        // half of the window and then soft-deleted, so its rows exercise the
        // "Deleted" destination-row treatment while still carrying real
        // historical figures and keeping its drill-through link (Screen 3
        // AC6; `Q-11-03(9)`'s destination half — unlike a deleted *proxy*,
        // a deleted *destination*'s link stays live).
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

        $normalProxies = [
            [
                'proxy' => $paymentsProxy,
                'destinations' => [
                    ['destination' => $primaryDestination, 'minOffset' => 0],
                    ['destination' => $billingDestination, 'minOffset' => 0],
                    // `legacy` only receives traffic on the older half of the
                    // window (10-29 days ago) — traffic that predates its
                    // soft-delete below, matching how a real destination
                    // would accumulate history before being removed.
                    ['destination' => $legacyDestination, 'minOffset' => 10],
                ],
                'meanDailyVolume' => random_int(50, 90),
                'weights' => $this->randomSettlementWeights(),
            ],
        ];

        // 1-10 total "normal" proxies (Owner's ask) — "Payments Webhook"
        // above is always one of them; the rest are drawn from the name pool
        // with plausible, distinct destination sets, so the Dashboard's
        // Proxies table has proxies to sort by rather than numbered clones.
        $proxyCount = random_int(1, 10);
        $extraNames = collect(self::PROXY_NAME_POOL)->shuffle()->take($proxyCount - 1);

        foreach ($extraNames as $name) {
            $proxy = Proxy::factory()->create([
                'team_id' => $teamA->id,
                'name' => $name,
            ]);

            $destinations = array_values(collect($this->destinationSpecsFor($name))
                ->map(fn (array $spec) => [
                    'destination' => Destination::factory()->create([
                        'proxy_id' => $proxy->id,
                        'team_id' => $teamA->id,
                        'url' => $spec['url'],
                        'http_method' => $spec['method'],
                    ]),
                    'minOffset' => 0,
                ])
                ->all());

            $normalProxies[] = [
                'proxy' => $proxy,
                'destinations' => $destinations,
                // Deliberately wide (20-90/day) so the fleet has a visibly
                // busier proxy and a visibly quieter one to sort by.
                'meanDailyVolume' => random_int(20, 90),
                'weights' => $this->randomSettlementWeights(),
            ];
        }

        // The deliberate zero-traffic gap day (AC16 densification must show
        // a real zero-count day, not a hole) lives on a SINGLE lower-volume
        // proxy rather than across the whole team, so the team-level
        // Dashboard trend stays stable. Prefer a proxy other than "Payments
        // Webhook" (the main showcase) when the fleet has more than one
        // proxy; fall back to it when it's the only proxy this run has.
        $gapCandidates = count($normalProxies) > 1
            ? array_values(array_filter($normalProxies, fn (array $p) => $p['proxy']->id !== $paymentsProxy->id))
            : $normalProxies;
        usort($gapCandidates, fn (array $a, array $b) => $a['meanDailyVolume'] <=> $b['meanDailyVolume']);
        $gapProxyId = $gapCandidates[0]['proxy']->id;
        $gapProxyName = $gapCandidates[0]['proxy']->name;

        foreach ($normalProxies as $spec) {
            $dayOffsets = $spec['proxy']->id === $gapProxyId
                ? array_values(array_diff(range(0, 29), [15]))
                : range(0, 29);

            $attemptRows = array_merge($attemptRows, $this->seedProxyDeliveries(
                $teamA,
                $spec['proxy'],
                $spec['destinations'],
                $dayOffsets,
                $spec['meanDailyVolume'],
                $spec['weights'],
            ));
        }

        $legacyDestination->delete();

        // --- Proxy: "Quiet Integration" — zero traffic, ever. ---
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

        // --- Proxy: "Retired Webhook" — soft-deleted, with history. ---
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
            range(0, 19),
            random_int(10, 20),
            $this->randomSettlementWeights(),
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
            range(0, 29),
            random_int(10, 20),
            $this->randomSettlementWeights(),
        ));

        // One bulk insert for every attempt row collected above, across
        // every proxy — each row already carries its own explicit
        // `created_at`/`updated_at`, so this single statement never touches
        // Eloquent's timestamp machinery.
        foreach (array_chunk($attemptRows, 200) as $chunk) {
            DB::table('delivery_attempts')->insert($chunk);
        }

        $this->printSummary($teamA, $ownerA, $normalProxies, $gapProxyName, $quietProxy, $retiredProxy, $teamB, $ownerB, $teamBProxy);
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
     * A distinct destination set for one of the randomly-generated "normal"
     * proxies — 1-3 destinations on a domain slugged from the proxy's name,
     * each on a different plausible path, so no two proxies' destinations
     * look like numbered clones.
     *
     * @return list<array{url: string, method: HttpMethod}>
     */
    private function destinationSpecsFor(string $proxyName): array
    {
        $domain = Str::slug($proxyName).'.example.com';
        $paths = collect(self::DESTINATION_PATH_POOL)->shuffle()->values();
        $count = random_int(1, 3);

        return array_values(collect(range(0, $count - 1))
            ->map(fn (int $i) => [
                'url' => "https://{$domain}{$paths[$i]}",
                'method' => random_int(0, 1) === 0 ? HttpMethod::Post : HttpMethod::Put,
            ])
            ->all());
    }

    /**
     * A per-proxy set of settlement-shape weights (out of 1000), perturbed
     * within a "realistic, healthy webhook proxy" band so the fleet has
     * visible variation without any proxy reading as broken:
     *
     * - terminal failure: 1.0%-4.0% of deliveries never arrive.
     * - success after two retries: 0.3%-1.0% — multi-retry deliveries are
     *   rarer than single-retry ones.
     * - success after one retry: 1.5%-3.0%.
     * - clean, first-attempt success: the remainder (roughly 93%-97%).
     *
     * At the delivery grain this lands success in the high-90s. At the
     * attempt grain it lands meaningfully lower — every retry and every
     * terminal failure spends two or three failed attempts to produce one
     * delivery outcome — which is the divergence AC13/AC14 exist to show,
     * just at proportions a real integration would actually produce rather
     * than today's near-coin-flip failure rate.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [cleanSuccess, oneRetry, twoRetries, terminalFailure]
     */
    private function randomSettlementWeights(): array
    {
        $terminalFailure = random_int(10, 40);
        $twoRetries = random_int(3, 10);
        $oneRetry = random_int(15, 30);
        $cleanSuccess = 1000 - $terminalFailure - $twoRetries - $oneRetry;

        return [$cleanSuccess, $oneRetry, $twoRetries, $terminalFailure];
    }

    /**
     * Create deliveries and their attempts for one proxy across a set of
     * "days ago" offsets, split across the given destinations. Each day's
     * delivery count varies naturally around `$meanDailyVolume` (Â±20%,
     * `dailyDeliveryCount()`) rather than being identical every day, and is
     * high enough that a daily success rate is computed over many
     * deliveries — landing in a stable band instead of swinging between 0%,
     * 50% and 100% the way two-a-day volume does — and that, once the
     * 24-hour window buckets hourly (PRD-11 Amendment B, not yet
     * implemented), individual hours carry enough deliveries to read
     * sensibly too.
     *
     * Every ~9th delivery (`$sequence % 9 === 0`) is a replay (`kind`), so
     * the live-vs-replay split is never all-live. Roughly 1 in 10 resolved
     * attempts gets an outlier duration (2.5-7s vs. the normal 40-380ms),
     * which is what makes the average and the 95th percentile read as
     * genuinely different numbers rather than coincidentally equal.
     *
     * @param  list<array{destination: Destination, minOffset: int}>  $destinations  each destination and the largest "days ago" value it stops being eligible below — lets one destination's traffic be confined to the older part of the window, ahead of a later soft-delete
     * @param  list<int>  $dayOffsets  "days ago" values (0 = today) to place deliveries on
     * @param  array{0: int, 1: int, 2: int, 3: int}  $settlementWeights  this proxy's fixed `randomSettlementWeights()` result — held constant across the whole run so one proxy reads consistently healthier or busier than another
     * @return list<array<string, mixed>> `delivery_attempts` rows, NOT yet inserted — the caller batches every proxy's rows into one final bulk insert
     */
    private function seedProxyDeliveries(Team $team, Proxy $proxy, array $destinations, array $dayOffsets, int $meanDailyVolume, array $settlementWeights): array
    {
        $attemptRows = [];

        foreach ($dayOffsets as $offset) {
            $eligible = array_values(array_filter($destinations, fn (array $d) => $offset >= $d['minOffset']));
            $dayCount = $this->dailyDeliveryCount($meanDailyVolume);

            for ($n = 0; $n < $dayCount; $n++) {
                $destination = $eligible[$this->sequence % count($eligible)]['destination'];
                $kind = $this->sequence % 9 === 0 ? DispatchKind::Replay : DispatchKind::Original;
                [$deliveryStatus, $attemptPlan] = $this->settlementPlan($settlementWeights);
                // Computed per delivery, not once per day, so a day's
                // deliveries spread naturally across its hours instead of
                // landing on the exact same instant.
                $dayTimestamp = $this->dayTimestamp($offset);

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
     * One day's delivery count, varied naturally (Â±20%) around
     * `$meanDailyVolume` rather than identical every day — modest enough
     * that the series still reads as "a stable service with minor
     * movement", not day-to-day noise.
     */
    private function dailyDeliveryCount(int $meanDailyVolume): int
    {
        $variation = random_int(80, 120) / 100;

        return max(1, (int) round($meanDailyVolume * $variation));
    }

    /**
     * The delivery-level outcome plus the ordered attempt-level outcomes for
     * one delivery, drawn from the given per-proxy settlement weights (see
     * `randomSettlementWeights()`):
     *
     * - clean success — one succeeded attempt. No retry involved.
     * - success after one retry — attempt 1 fails, attempt 2 succeeds.
     *   Delivery-level: succeeded. Attempt-level: one success, one failure.
     *   This (and the next case) is the source of the both-unit divergence
     *   the feature exists to show, and of "eventual success" / retry
     *   volume (AC19).
     * - success after two retries — two failed attempts then a success.
     *   Same shape, more retry volume.
     * - terminal failure — three failed attempts, delivery never succeeds.
     *   Feeds "Terminal failures" (both the table column and the Retry &
     *   replay tile) with real, drill-through-able rows.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $weights  [cleanSuccess, oneRetry, twoRetries, terminalFailure], summing to 1000
     * @return array{0: DeliveryStatus, 1: list<AttemptStatus>}
     */
    private function settlementPlan(array $weights): array
    {
        [$cleanSuccess, $oneRetry, $twoRetries, $terminalFailure] = $weights;
        $roll = random_int(1, 1000);

        return match (true) {
            $roll <= $cleanSuccess => [DeliveryStatus::Succeeded, [AttemptStatus::Succeeded]],
            $roll <= $cleanSuccess + $oneRetry => [DeliveryStatus::Succeeded, [AttemptStatus::Failed, AttemptStatus::Succeeded]],
            $roll <= $cleanSuccess + $oneRetry + $twoRetries => [DeliveryStatus::Succeeded, [AttemptStatus::Failed, AttemptStatus::Failed, AttemptStatus::Succeeded]],
            default => [DeliveryStatus::Failed, [AttemptStatus::Failed, AttemptStatus::Failed, AttemptStatus::Failed]],
        };
    }

    /**
     * The settlement timestamp for a given "days ago" offset — used as both
     * `created_at` and `updated_at` on the delivery this row belongs to (see
     * class docblock). Offset 0 ("today") is anchored to a random point
     * between the start of today and now — spanning however much of today
     * has elapsed, rather than only the last few hours — so the rolling
     * 24-hour window (which straddles today and part of yesterday) sees a
     * natural spread of activity rather than one artificial spike, and can
     * never land in the future relative to when this seeder runs.
     */
    private function dayTimestamp(int $offset): CarbonImmutable
    {
        if ($offset === 0) {
            $elapsedMinutes = max(1, (int) CarbonImmutable::now()->startOfDay()->diffInMinutes(CarbonImmutable::now()));

            return CarbonImmutable::now()->startOfDay()->addMinutes(random_int(0, $elapsedMinutes));
        }

        return CarbonImmutable::now()->startOfDay()->subDays($offset)
            ->addHours(random_int(1, 22))
            ->addMinutes(random_int(0, 59));
    }

    /**
     * @param  list<array{proxy: Proxy, destinations: list<array{destination: Destination, minOffset: int}>, meanDailyVolume: int, weights: array{0: int, 1: int, 2: int, 3: int}}>  $normalProxies
     */
    private function printSummary(
        Team $teamA,
        User $ownerA,
        array $normalProxies,
        string $gapProxyName,
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
        $this->command->line("  Dashboard: /{$teamA->slug}/dashboard");
        $this->command->line('');
        $this->command->line('  Normal traffic fleet ('.count($normalProxies).' proxies):');

        foreach ($normalProxies as $spec) {
            /** @var Proxy $proxy */
            $proxy = $spec['proxy'];
            $gapNote = $proxy->name === $gapProxyName ? ' — carries the 30-day gap day (day offset 15, zero traffic)' : '';
            $showcaseNote = $proxy->name === 'Payments Webhook' ? ' (main showcase: divergence, retries, terminal failures, latency spread, deleted destination)' : '';
            $this->command->line("    {$proxy->name}: /{$teamA->slug}/proxies/{$proxy->id} (~{$spec['meanDailyVolume']}/day){$showcaseNote}{$gapNote}");
        }

        $this->command->line('');
        $this->command->line("  Quiet Integration:  /{$teamA->slug}/proxies/{$quietProxy->id} (zero traffic)");
        $this->command->line("  Retired Webhook:    /{$teamA->slug}/proxies/{$retiredProxy->id} (soft-deleted proxy, id {$retiredProxy->id} — visible as a Deleted row on the Dashboard's Proxies table, not directly navigable once trashed)");
        $this->command->line('');
        $this->command->line("Team B: {$teamB->name} (slug: {$teamB->slug})");
        $this->command->line("  Login: {$ownerB->email} / password: password");
        $this->command->line("  Dashboard:        /{$teamB->slug}/dashboard");
        $this->command->line("  Team B Notifications: /{$teamB->slug}/proxies/{$teamBProxy->id}");
    }
}
