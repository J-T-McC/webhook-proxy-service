<?php

namespace Tests\Feature\Proxies;

use App\Models\Proxy;
use App\Models\User;
use Tests\TestCase;

/**
 * T45 (R4; plan Technical ruling 7) — `destinations.*.credential_secret` never
 * reaches the session's flashed old input after a failed validation, for both
 * Store and Update. `bootstrap/app.php`'s `dontFlash` list flashes old input via
 * `Arr::except($request->input(), $this->dontFlash)`, and `Arr::forget()` (what
 * `Arr::except()` uses under the hood) has no wildcard support — it cannot reach
 * a key nested under a numeric array index. `StoreProxyRequest`/`UpdateProxyRequest`
 * therefore scrub the nested value themselves in `failedValidation()`.
 */
class OldInputScrubTest extends TestCase
{
    private function actingUser(): User
    {
        $user = User::factory()->createQuietly();
        $user->switchTeam($user->currentTeam);

        return $user;
    }

    public function test_store_failed_validation_flashes_no_credential_secret(): void
    {
        $user = $this->actingUser();

        // 'name' omitted -> guaranteed 422/redirect-back with input flashed.
        $this->actingAs($user)->post(
            route('proxies.store', ['current_team' => $user->currentTeam->slug]),
            [
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'url' => 'https://a.example.com/hook',
                        'http_method' => 'POST',
                        'credential_header_name' => 'Authorization',
                        'credential_secret' => 'do-not-flash-this-secret',
                    ],
                ],
            ],
        )->assertInvalid(['name']);

        $oldInput = session('_old_input');

        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('credential_secret', $oldInput['destinations'][0]);
        $this->assertSame('https://a.example.com/hook', $oldInput['destinations'][0]['url']);
        $this->assertStringNotContainsString('do-not-flash-this-secret', serialize($oldInput));
    }

    public function test_update_failed_validation_flashes_no_credential_secret(): void
    {
        $user = $this->actingUser();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $user->current_team_id]);

        // 'name' omitted -> guaranteed 422/redirect-back with input flashed.
        $this->actingAs($user)->put(
            route('proxies.update', ['current_team' => $user->currentTeam->slug, 'proxy' => $proxy->id]),
            [
                'mode' => 'simple',
                'processing_mode' => 'async',
                'destinations' => [
                    [
                        'url' => 'https://a.example.com/hook',
                        'http_method' => 'POST',
                        'credential_header_name' => 'Authorization',
                        'credential_secret' => 'do-not-flash-this-secret',
                    ],
                ],
            ],
        )->assertInvalid(['name']);

        $oldInput = session('_old_input');

        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('credential_secret', $oldInput['destinations'][0]);
        $this->assertSame('https://a.example.com/hook', $oldInput['destinations'][0]['url']);
        $this->assertStringNotContainsString('do-not-flash-this-secret', serialize($oldInput));
    }
}
