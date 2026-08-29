<?php

namespace App\Support;

/**
 * The product default sensitive-field list (AC12; plan-10 Technical ruling
 * 10; ADR-024 Decision 5) — 23 names across the three families AC12 names,
 * fixed at technical design. `secret`, `api_key`, `private_key` and
 * `client_secret` are deliberately excluded: AC12 also forbids a member
 * removing a default, so a wrong entry is permanent and invisible to them,
 * while a missing one is a two-second AC13 addition. Displayed literally on
 * Screen 2 (correction C4); compared only in normalised form.
 */
class SensitiveFields
{
    /**
     * @var list<string>
     */
    public const DEFAULTS = [
        // Password family.
        'password',
        'passwd',
        'pwd',
        'passphrase',
        'current_password',
        'new_password',
        'old_password',
        'password_confirmation',
        // Token family.
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'auth_token',
        'api_token',
        'bearer_token',
        // Credit card family.
        'credit_card',
        'credit_card_number',
        'card_number',
        'cc_number',
        'cvv',
        'cvc',
        'csc',
        'card_security_code',
    ];

    /**
     * Normalise a field name for case/separator-insensitive comparison
     * (ADR-024 Decision 4): lowercase, then strip every character that is not
     * `a`-`z` or `0`-`9`.
     */
    public static function normalise(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($name));
    }
}
