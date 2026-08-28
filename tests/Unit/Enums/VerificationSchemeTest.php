<?php

namespace Tests\Unit\Enums;

use App\Enums\VerificationScheme;
use Tests\TestCase;

/**
 * T16 (AC23, AC50) — the closed two-case verification scheme registry.
 */
class VerificationSchemeTest extends TestCase
{
    public function test_exactly_the_two_documented_cases_exist(): void
    {
        $this->assertSame(
            ['standard-webhooks', 'shared-secret'],
            array_map(fn (VerificationScheme $case): string => $case->value, VerificationScheme::cases()),
        );
    }

    public function test_an_unknown_scheme_resolves_to_null(): void
    {
        $this->assertNull(VerificationScheme::tryFrom('github'));
    }
}
