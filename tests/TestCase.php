<?php

namespace Tests;

use App\Services\OutboundAddressGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    use FasterRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // #18: creating or re-pointing a destination dispatches a validation
        // challenge, and the queue runs synchronously under test — so without
        // this the suite performs real DNS lookups for every fake destination
        // hostname any test happens to create.
        //
        // The default resolver returns nothing, which the guard treats as
        // unresolvable and refuses. Tests that are ABOUT sending a challenge
        // rebind this with an answer of their own.
        $this->app->bind(
            OutboundAddressGuard::class,
            fn () => new OutboundAddressGuard(fn (string $host) => []),
        );
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
