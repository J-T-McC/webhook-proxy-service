<?php

namespace Tests\Unit\Support;

use App\Enums\MatchSource;
use App\Models\Proxy;
use App\Models\Team;
use App\Services\SensitiveFieldMatcher;
use App\Support\PayloadObfuscator;
use Tests\TestCase;

class PayloadObfuscatorTest extends TestCase
{
    private function matcherWithAdditions(array $additions = []): SensitiveFieldMatcher
    {
        $team = Team::factory()->createQuietly();
        $proxy = Proxy::factory()->createQuietly(['team_id' => $team->id, 'sensitive_fields' => $additions]);

        return new SensitiveFieldMatcher($proxy);
    }

    public function test_a_sensitive_field_at_depth_4_and_inside_an_array_element_is_obfuscated(): void
    {
        $matcher = $this->matcherWithAdditions();

        $document = [
            'a' => ['b' => ['c' => ['password' => 'deep-secret']]],
            'customers' => [
                ['name' => 'alice', 'password' => 'p1'],
                ['name' => 'bob', 'password' => 'p2'],
            ],
        ];

        [$obfuscated, $pointerIndex] = PayloadObfuscator::obfuscate($document, $matcher);

        $this->assertNull($obfuscated['a']['b']['c']['password']);
        $this->assertNull($obfuscated['customers'][0]['password']);
        $this->assertNull($obfuscated['customers'][1]['password']);

        $this->assertSame(MatchSource::Default, $pointerIndex['/a/b/c/password']);
        $this->assertSame(MatchSource::Default, $pointerIndex['/customers/0/password']);
        $this->assertSame(MatchSource::Default, $pointerIndex['/customers/1/password']);
    }

    public function test_a_sensitive_value_that_is_an_object_or_array_is_replaced_whole_never_walked_into(): void
    {
        $matcher = $this->matcherWithAdditions();

        $document = [
            'token' => ['access' => 'a', 'refresh' => 'b'],
            'password' => ['nested' => ['deeper' => 'value']],
        ];

        [$obfuscated, $pointerIndex] = PayloadObfuscator::obfuscate($document, $matcher);

        $this->assertNull($obfuscated['token']);
        $this->assertNull($obfuscated['password']);
        $this->assertArrayNotHasKey('/token/access', $pointerIndex);
        $this->assertArrayNotHasKey('/password/nested', $pointerIndex);
        $this->assertArrayNotHasKey('/password/nested/deeper', $pointerIndex);
        $this->assertSame(['/token' => MatchSource::Default, '/password' => MatchSource::Default], $pointerIndex);
    }

    public function test_field_names_and_non_sensitive_values_are_untouched_and_structure_is_preserved(): void
    {
        $matcher = $this->matcherWithAdditions();

        $document = [
            'customer_name' => 'Alice',
            'items' => [1, 2, 3],
            'password' => 'secret',
        ];

        [$obfuscated] = PayloadObfuscator::obfuscate($document, $matcher);

        $this->assertSame('Alice', $obfuscated['customer_name']);
        $this->assertSame([1, 2, 3], $obfuscated['items']);
        $this->assertSame(array_keys($document), array_keys($obfuscated));
        $this->assertCount(3, $obfuscated['items']);
    }

    public function test_pointer_index_records_default_for_default_and_addition_for_addition(): void
    {
        $matcher = $this->matcherWithAdditions(['ssn_last4']);

        $document = [
            'password' => 'p',
            'ssn_last4' => '1234',
        ];

        [, $pointerIndex] = PayloadObfuscator::obfuscate($document, $matcher);

        $this->assertSame(MatchSource::Default, $pointerIndex['/password']);
        $this->assertSame(MatchSource::Addition, $pointerIndex['/ssn_last4']);
    }

    public function test_two_different_real_values_that_both_matched_produce_identical_null_output(): void
    {
        $matcher = $this->matcherWithAdditions();

        $document = [
            'password' => 'value-one',
            'password_confirmation' => 'a-completely-different-value',
        ];

        [$obfuscated] = PayloadObfuscator::obfuscate($document, $matcher);

        $this->assertNull($obfuscated['password']);
        $this->assertNull($obfuscated['password_confirmation']);
        $this->assertSame($obfuscated['password'], $obfuscated['password_confirmation']);
    }
}
