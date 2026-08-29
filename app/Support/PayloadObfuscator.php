<?php

namespace App\Support;

use App\Enums\MatchSource;
use App\Services\SensitiveFieldMatcher;

/**
 * Walks a decoded JSON document and replaces every sensitive-field value
 * whole with `null` (AC15, AC16, AC17, C6; plan-10 § Architecture D; ADR-024
 * Decisions 2 and 4). Pure class: no DB, no I/O, no clock — deterministic
 * given the same document and matcher. Matching is name-only, applied at any
 * depth, including inside array elements; an array index is a position,
 * never a name, and is never tested against the sensitive-field list.
 *
 * Returns `[document, pointerIndex]`: `document` is the same tree with every
 * matched value replaced by `null`, whatever its original type — a matched
 * value that is itself an object or array is replaced whole and never walked
 * into (C6), so none of its sub-keys survive at any depth. `pointerIndex`
 * maps an RFC 6901 JSON Pointer to the `MatchSource` that matched, for every
 * replaced value. Field names and non-sensitive values are returned
 * untouched; the document's structure (keys present, array lengths) is
 * unchanged except for the values actually replaced.
 */
class PayloadObfuscator
{
    /**
     * @param  mixed  $document  a decoded JSON tree (`json_decode(..., true)`)
     * @return array{0: mixed, 1: array<string, MatchSource>}
     */
    public static function obfuscate(mixed $document, SensitiveFieldMatcher $matcher): array
    {
        $pointerIndex = [];
        $obfuscated = self::walk($document, '', $matcher, $pointerIndex);

        return [$obfuscated, $pointerIndex];
    }

    /**
     * @param  array<string, MatchSource>  $pointerIndex
     */
    private static function walk(mixed $node, string $pointer, SensitiveFieldMatcher $matcher, array &$pointerIndex): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $isList = array_is_list($node);
        $result = [];

        foreach ($node as $key => $value) {
            $segment = $isList ? (string) $key : self::escapeSegment((string) $key);
            $childPointer = "{$pointer}/{$segment}";

            if (! $isList) {
                $match = $matcher->matchFor((string) $key);

                if ($match !== null) {
                    $result[$key] = null;
                    $pointerIndex[$childPointer] = $match;

                    continue;
                }
            }

            $result[$key] = self::walk($value, $childPointer, $matcher, $pointerIndex);
        }

        return $result;
    }

    /**
     * Escape a JSON Pointer reference token (RFC 6901 § 3): `~` first, then
     * `/` — order matters, since escaping `/` before `~` would double-escape
     * the `~` the first substitution introduces.
     */
    private static function escapeSegment(string $segment): string
    {
        return str_replace('/', '~1', str_replace('~', '~0', $segment));
    }
}
