<?php

namespace App\Enums;

/**
 * Which sensitive-field list matched a name (AC13, AC14; ADR-024 Decisions 2
 * and 4; design-10 correction C3). `Default` beats `Addition` when a name is
 * in both lists — removing the addition would not unhide a default-matched
 * value, so describing it as an addition would offer a remedy that does not
 * work (plan-10 Technical ruling 2).
 */
enum MatchSource: string
{
    case Default = 'default';
    case Addition = 'addition';
}
