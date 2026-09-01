<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Enums\ProcessingMode;
use App\Models\Destination;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the proxies the k6 load harness drives
 * (`docs/briefs/load-testing-harness.md`).
 *
 * **Every destination this command writes addresses the sink**, and it writes
 * no other kind. That is one of the three measures keeping a load run off a
 * real endpoint; the other two are the harness's own database and the
 * pre-flight check in `run.sh`, which refuses to start when a destination
 * pointing anywhere else exists.
 *
 * Destinations are created already `Validated`, because only a validated
 * destination receives traffic (#18 AC8) and `OutboundAddressGuard` — which
 * `SendDestinationValidationChallenge` consults, but `DeliverToDestination`
 * does not — would refuse the challenge to a sink on a private address. The
 * factory default is already this state, so nothing here is test-only
 * behaviour bolted onto production code.
 *
 * Idempotent by deletion, unlike `e2e:seed`. It owns its whole database and
 * every run must start from an empty ledger for the recorded drain rate to
 * mean anything, so it clears its own rows rather than reusing them.
 */
class SeedLoadData extends Command
{
    protected $signature = 'load:seed
        {--fifo=10 : Proxies to create in FIFO processing mode}
        {--async=10 : Proxies to create in Async processing mode}
        {--destinations=2 : Destinations per proxy}
        {--sink=http://host.docker.internal:9000 : Base URL of the load sink}
        {--json : Emit the seeded proxies as JSON}';

    protected $description = 'Create the proxies and sink destinations used by the k6 load harness';

    private const EMAIL = 'load@example.com';

    private const TEAM = 'Load Testing';

    /**
     * The only database this command will write to.
     *
     * `clear()` empties every table it touches, so pointing this command at a
     * development database destroys that database's contents. Refusing any
     * other name is what makes the harness's isolation a property of the code
     * rather than of whoever typed the command. Deliberately a constant with no
     * environment override: an override is one more thing that can be set
     * wrongly, and the whole value of this guard is that it cannot be.
     */
    private const DATABASE = 'webhook_load';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('load:seed refuses to run in production.');

            return self::FAILURE;
        }

        $actual = (string) DB::connection()->getDatabaseName();

        if ($actual !== self::DATABASE) {
            $this->components->error(
                'load:seed refuses to run: it deletes every row in the database it seeds, and this '
                ."connection is '{$actual}', not '".self::DATABASE."'. Point DB_DATABASE at the load "
                .'database before running it.'
            );

            return self::FAILURE;
        }

        $sink = rtrim((string) $this->option('sink'), '/');

        if (! str_starts_with($sink, 'http://') && ! str_starts_with($sink, 'https://')) {
            $this->components->error("--sink must be an absolute URL, got: {$sink}");

            return self::FAILURE;
        }

        $this->clear();

        $user = User::factory()->create([
            'email' => self::EMAIL,
            'email_verified_at' => now(),
        ]);

        // Through the same action registration uses, so membership and
        // current-team state match a real account rather than a hand-built one.
        $team = app(CreateTeam::class)->handle($user, self::TEAM, isPersonal: true);

        $seeded = [];

        foreach ([ProcessingMode::Fifo, ProcessingMode::Async] as $mode) {
            $count = (int) $this->option($mode === ProcessingMode::Fifo ? 'fifo' : 'async');

            for ($i = 1; $i <= $count; $i++) {
                $seeded[] = $this->seedProxy($team, $mode, $i, $sink);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['sink' => $sink, 'proxies' => $seeded], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Seeded %d proxies, all destinations on %s', count($seeded), $sink));

        return self::SUCCESS;
    }

    /**
     * @return array{id: int, mode: string, token: string, ingest_url: string, destinations: int}
     */
    private function seedProxy(Team $team, ProcessingMode $mode, int $index, string $sink): array
    {
        $proxy = Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'name' => sprintf('load-%s-%02d', $mode->value, $index),
            'processing_mode' => $mode,
        ]);

        $destinations = (int) $this->option('destinations');

        for ($d = 1; $d <= $destinations; $d++) {
            Destination::factory()->createQuietly([
                'proxy_id' => $proxy->id,
                'team_id' => $team->id,
                'url' => "{$sink}/d{$d}",
            ]);
        }

        return [
            'id' => $proxy->id,
            'mode' => $mode->value,
            'token' => $proxy->ingest_token,
            'ingest_url' => $proxy->ingestUrl(),
            'destinations' => $destinations,
        ];
    }

    /**
     * Drops everything a previous run left behind.
     *
     * Ordered child-first: the schema's foreign keys restrict rather than
     * cascade, so deleting a proxy that still has events fails. Truncation is
     * not an option for the same reason.
     */
    private function clear(): void
    {
        DB::table('delivery_attempts')->delete();
        DB::table('proxy_secrets')->delete();
        DB::table('dispatched_payloads')->delete();
        DB::table('deliveries')->delete();
        DB::table('fifo_dispatches')->delete();
        DB::table('webhook_events')->delete();
        DB::table('destinations')->delete();
        DB::table('proxies')->delete();
        DB::table('team_invitations')->delete();
        DB::table('team_members')->delete();
        DB::table('teams')->delete();
        DB::table('users')->delete();
    }
}
