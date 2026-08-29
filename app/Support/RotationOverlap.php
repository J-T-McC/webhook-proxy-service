<?php

namespace App\Support;

/**
 * AC29's fixed 24-hour overlap window between a rotated secret and its
 * replacement. A class constant, not config (plan-10 Technical ruling 3
 * carried into ADR-021 Decision 3) — an env key would make the window a
 * product-tunable value, which is exactly what AC29 rules out. Same
 * reasoning as `App\Support\StandardWebhooks::TOLERANCE_SECONDS`.
 */
final class RotationOverlap
{
    final public const int HOURS = 24;
}
