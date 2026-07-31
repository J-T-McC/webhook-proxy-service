<?php

namespace App\Http\Resources;

use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The per-destination shape shared by the proxy show/edit Inertia pages.
 *
 * @mixin Destination
 */
class DestinationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'http_method' => $this->http_method->value,
        ];
    }
}
