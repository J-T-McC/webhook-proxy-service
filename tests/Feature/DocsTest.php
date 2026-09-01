<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocsTest extends TestCase
{
    public function test_guests_can_read_the_documentation(): void
    {
        $response = $this->get(route('docs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Docs'),
        );
    }

    public function test_authenticated_users_see_the_same_documentation(): void
    {
        $user = User::factory()->createQuietly();

        $response = $this->actingAs($user)->get(route('docs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Docs'),
        );
    }
}
