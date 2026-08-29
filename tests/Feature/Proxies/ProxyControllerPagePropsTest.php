<?php

namespace Tests\Feature\Proxies;

use App\Models\Proxy;
use App\Models\User;
use App\Support\SensitiveFields;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T11 — `defaultSensitiveFieldNames` page prop on `create()`/`edit()` (AC12;
 * plan-10 Technical ruling 3): single-sourced from `SensitiveFields::DEFAULTS`,
 * present on both routes, absent from `index()`.
 */
class ProxyControllerPagePropsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    public function test_create_emits_the_default_sensitive_field_names_prop_exactly(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)
            ->get(route('proxies.create', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Create')
                ->where('defaultSensitiveFieldNames', SensitiveFields::DEFAULTS)
            );
    }

    public function test_edit_emits_the_default_sensitive_field_names_prop_exactly(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.edit', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Edit')
                ->where('defaultSensitiveFieldNames', SensitiveFields::DEFAULTS)
            );
    }

    public function test_index_gains_no_default_sensitive_field_names_prop(): void
    {
        $user = $this->actingUser();
        Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        $this->actingAs($user)
            ->get(route('proxies.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proxies/Index')
                ->missing('defaultSensitiveFieldNames')
            );
    }
}
