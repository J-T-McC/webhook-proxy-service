<?php

namespace App\Enums;

/**
 * Outbound HTTP method used to replay a webhook to a destination (V1 / AC3).
 */
enum HttpMethod: string
{
    case Post = 'POST';
    case Put = 'PUT';
}
