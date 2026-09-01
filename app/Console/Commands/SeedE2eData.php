<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Seeds the fixed accounts the Playwright suite signs in as
 * (docs/briefs/e2e-playwright-coverage.md).
 *
 * Idempotent by reuse, never by deletion: proxies, events and deliveries hold
 * restricting foreign keys, so a re-seed that tried to clear its own rows would
 * fail as soon as a spec had captured one event. Specs create the data they
 * assert on and name it uniquely instead.
 */
class SeedE2eData extends Command
{
    protected $signature = 'e2e:seed {--json : Emit the seeded ids and slugs as JSON}';

    protected $description = 'Create or refresh the fixed accounts used by the Playwright end-to-end suite';

    public const PASSWORD = 'e2e-password';

    private const MEMBER_EMAIL = 'e2e@example.com';

    private const OUTSIDER_EMAIL = 'e2e-outsider@example.com';

    private const SIGN_IN_EMAIL = 'e2e-signin@example.com';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('e2e:seed refuses to run in production.');

            return self::FAILURE;
        }

        $member = $this->user(self::MEMBER_EMAIL, 'E2E Member');
        $memberTeam = $this->team($member, 'E2E Team');

        // A second account for the specs that exercise the login form itself.
        // Fortify throttles by email, so they must not spend the budget the
        // shared session setup needs.
        $signIn = $this->user(self::SIGN_IN_EMAIL, 'E2E Sign In');
        $signInTeam = $this->team($signIn, 'E2E Sign In Team');

        // The isolation spec asserts this team's proxy is invisible to the
        // member above, who is deliberately not a member of it.
        $outsider = $this->user(self::OUTSIDER_EMAIL, 'E2E Outsider');
        $outsiderTeam = $this->team($outsider, 'E2E Other Team');
        $foreignProxy = Proxy::withoutGlobalScopes()
            ->where('team_id', $outsiderTeam->id)
            ->where('name', 'Foreign Proxy')
            ->first()
            ?? Proxy::factory()->createQuietly([
                'team_id' => $outsiderTeam->id,
                'name' => 'Foreign Proxy',
            ]);

        $state = [
            'password' => self::PASSWORD,
            'member' => ['email' => $member->email, 'teamSlug' => $memberTeam->slug],
            'signIn' => ['email' => $signIn->email, 'teamSlug' => $signInTeam->slug],
            'outsider' => ['email' => $outsider->email, 'teamSlug' => $outsiderTeam->slug],
            'foreignProxy' => ['id' => $foreignProxy->id, 'name' => $foreignProxy->name],
        ];

        $this->line($this->option('json')
            ? (string) json_encode($state, JSON_PRETTY_PRINT)
            : 'Seeded '.$member->email.' (team '.$memberTeam->slug.').');

        return self::SUCCESS;
    }

    private function user(string $email, string $name): User
    {
        $user = User::firstOrNew(['email' => $email]);

        $user->fill(['name' => $name, 'password' => self::PASSWORD]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * The user's personal team, created through the same action registration
     * uses so membership and current-team state match a real signup.
     */
    private function team(User $user, string $name): Team
    {
        $team = Team::where('name', $name)->first();

        if ($team === null) {
            return app(CreateTeam::class)->handle($user, $name, isPersonal: true);
        }

        $user->switchTeam($team);

        return $team;
    }
}
