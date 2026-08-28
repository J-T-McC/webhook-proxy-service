<?php

namespace Tests\Unit\Services;

use App\Enums\MatchSource;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\SensitiveFieldMatcher;
use App\Support\SensitiveFields;
use Tests\TestCase;

class SensitiveFieldMatcherTest extends TestCase
{
    private function proxyWithAdditions(?array $additions): Proxy
    {
        $team = Team::factory()->createQuietly();

        return Proxy::factory()->createQuietly([
            'team_id' => $team->id,
            'sensitive_fields' => $additions,
        ]);
    }

    public function test_a_default_only_name_matches_default(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions(null));

        $this->assertSame(MatchSource::Default, $matcher->matchFor('password'));
    }

    public function test_a_proxy_addition_only_name_matches_addition(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions(['ssn_last4']));

        $this->assertSame(MatchSource::Addition, $matcher->matchFor('ssn_last4'));
    }

    public function test_a_name_in_both_lists_matches_default_the_tie_break(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions(['password']));

        $this->assertSame(MatchSource::Default, $matcher->matchFor('password'));
    }

    public function test_matching_is_exact_never_substring(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions(null));

        $this->assertNull($matcher->matchFor('tokenizer_version'));
        $this->assertNull($matcher->matchFor('token_count'));
        $this->assertNull($matcher->matchFor('tokens'));
        $this->assertSame(MatchSource::Default, $matcher->matchFor('token'));
    }

    public function test_an_unmatched_name_returns_null(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions(null));

        $this->assertNull($matcher->matchFor('customer_name'));
    }

    public function test_an_empty_proxy_addition_list_still_matches_every_default_name(): void
    {
        $matcher = new SensitiveFieldMatcher($this->proxyWithAdditions([]));

        foreach (SensitiveFields::DEFAULTS as $name) {
            $this->assertSame(MatchSource::Default, $matcher->matchFor($name));
        }
    }
}
