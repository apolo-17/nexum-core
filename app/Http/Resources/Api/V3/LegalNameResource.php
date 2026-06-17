<?php

namespace App\Http\Resources\Api\V3;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a LegalName model into the V3 API JSON representation.
 */
class LegalNameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'name'                      => $this->name,
            'priority'                  => $this->priority,
            'status'                    => $this->status->value,
            'clave_unica_denominacion'  => $this->clave_unica_denominacion,
            'authorization_timestamp'   => $this->authorization_timestamp?->toISOString(),
            'submitted_at'              => $this->submitted_at?->toISOString(),
        ];
    }
}
