<?php

namespace Tests\Unit\Support;

use App\Support\SensitiveFields;
use Tests\TestCase;

class SensitiveFieldsTest extends TestCase
{
    public function test_defaults_has_exactly_23_entries(): void
    {
        $this->assertCount(23, SensitiveFields::DEFAULTS);
    }

    public function test_no_two_defaults_collide_after_normalisation(): void
    {
        $normalised = array_map(SensitiveFields::normalise(...), SensitiveFields::DEFAULTS);

        $this->assertCount(count(SensitiveFields::DEFAULTS), array_unique($normalised));
    }

    public function test_every_default_is_already_in_normalised_equal_form_to_its_own_displayed_spelling(): void
    {
        // normalise() strips anything that is not a-z or 0-9, so a name already
        // "normalised-equal to its own spelling" is one containing only lowercase
        // letters, digits and underscores (the one separator normalise() removes).
        foreach (SensitiveFields::DEFAULTS as $name) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
            $this->assertSame(str_replace('_', '', $name), SensitiveFields::normalise($name));
        }
    }

    public function test_normalisation_is_case_and_separator_insensitive(): void
    {
        $this->assertSame(SensitiveFields::normalise('Password'), SensitiveFields::normalise('pass_word'));
        $this->assertSame(SensitiveFields::normalise('pass_word'), SensitiveFields::normalise('PASS-WORD'));
        $this->assertSame('password', SensitiveFields::normalise('Password'));
    }

    public function test_excluded_and_included_names(): void
    {
        $normalisedDefaults = array_map(SensitiveFields::normalise(...), SensitiveFields::DEFAULTS);

        $this->assertNotContains(SensitiveFields::normalise('secret'), $normalisedDefaults);
        $this->assertNotContains(SensitiveFields::normalise('api_key'), $normalisedDefaults);
        $this->assertNotContains(SensitiveFields::normalise('private_key'), $normalisedDefaults);
        $this->assertNotContains(SensitiveFields::normalise('client_secret'), $normalisedDefaults);

        $this->assertContains(SensitiveFields::normalise('cvv'), $normalisedDefaults);
        $this->assertContains(SensitiveFields::normalise('pwd'), $normalisedDefaults);
    }
}
