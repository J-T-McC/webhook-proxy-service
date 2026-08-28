<?php

namespace App\Enums;

/**
 * The closed inbound-verification scheme registry (AC23, AC50; ADR-022
 * Decision 2) — exactly two cases. Adding a third is a Project Owner
 * decision, never absorbed quietly.
 */
enum VerificationScheme: string
{
    case StandardWebhooks = 'standard-webhooks';
    case SharedSecret = 'shared-secret';
}
