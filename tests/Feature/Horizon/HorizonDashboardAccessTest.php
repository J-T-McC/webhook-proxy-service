<?php

namespace Tests\Feature\Horizon;

use App\Models\User;
use Tests\TestCase;

/**
 * Access control on the Horizon dashboard (`App\Http\Middleware\AuthenticateHorizon`).
 *
 * The dashboard exposes queue payloads, failure output and the ability to
 * retry or delete jobs, so these tests exist to pin the parts that would be
 * dangerous to regress silently: that it is closed by default, that an
 * unconfigured deployment is locked rather than open, and that an ordinary
 * authenticated team member is not thereby an operator.
 */
class HorizonDashboardAccessTest extends TestCase
{
    private function configureCredentials(string $user = 'ops', string $password = 'secret'): void
    {
        config([
            'horizon.basic_auth.username' => $user,
            'horizon.basic_auth.password' => $password,
        ]);
    }

    public function test_it_rejects_a_request_with_no_credentials(): void
    {
        $this->configureCredentials();

        $response = $this->get('/horizon');

        $response->assertStatus(401);
    }

    public function test_it_prompts_the_browser_for_credentials(): void
    {
        $this->configureCredentials();

        $response = $this->get('/horizon');

        // Without this header the browser shows a bare 401 body and never
        // offers its credential prompt, which is the only login form here.
        $response->assertHeader('WWW-Authenticate', 'Basic realm="Horizon", charset="UTF-8"');
    }

    public function test_it_rejects_a_wrong_password(): void
    {
        $this->configureCredentials();

        $response = $this->withBasicAuth('ops', 'wrong')->get('/horizon');

        $response->assertStatus(401);
    }

    public function test_it_rejects_a_wrong_username(): void
    {
        $this->configureCredentials();

        $response = $this->withBasicAuth('someone-else', 'secret')->get('/horizon');

        $response->assertStatus(401);
    }

    public function test_it_allows_the_configured_credentials(): void
    {
        $this->configureCredentials();

        $response = $this->withBasicAuth('ops', 'secret')->get('/horizon');

        $response->assertSuccessful();
    }

    /**
     * The fail-closed case, and the reason `passes()` checks for empty values
     * before comparing: a deployment that never set the environment variables
     * must get a locked dashboard, not one that accepts empty credentials.
     */
    public function test_it_fails_closed_when_no_credentials_are_configured(): void
    {
        config([
            'horizon.basic_auth.username' => null,
            'horizon.basic_auth.password' => null,
        ]);

        $this->get('/horizon')->assertForbidden();
        $this->withBasicAuth('', '')->get('/horizon')->assertForbidden();
        $this->withBasicAuth('ops', 'secret')->get('/horizon')->assertForbidden();
    }

    public function test_it_fails_closed_when_only_the_username_is_configured(): void
    {
        config([
            'horizon.basic_auth.username' => 'ops',
            'horizon.basic_auth.password' => null,
        ]);

        $this->withBasicAuth('ops', '')->get('/horizon')->assertForbidden();
    }

    /**
     * Operational access is deployment configuration, not a property of an
     * application account — this project has no superadmin role, so being
     * signed in must not by itself open the queue dashboard.
     */
    public function test_an_authenticated_application_user_still_needs_the_dashboard_credentials(): void
    {
        $this->configureCredentials();

        $response = $this->actingAs(User::factory()->create())->get('/horizon');

        $response->assertStatus(401);
    }
}
