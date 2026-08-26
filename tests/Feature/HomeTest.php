<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_guests_see_the_welcome_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Welcome'),
        );
    }

    public function test_authenticated_users_see_the_same_welcome_page(): void
    {
        $user = User::factory()->createQuietly();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Welcome'),
        );
    }
}
