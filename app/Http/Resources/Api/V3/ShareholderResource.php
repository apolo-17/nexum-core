<?php

namespace App\Http\Resources\Api\V3;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a Shareholder model into the V3 API JSON representation.
 */
class ShareholderResource extends JsonResource
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
            'id'                       => $this->id,
            'name'                     => $this->name,
            'nationality'              => $this->nationality,
            'passport_number'          => $this->passport_number,
            'participation_percentage' => (float) $this->participation_percentage,
            'role'                     => $this->role->value,
            'role_label'               => $this->role->label(),
            'email'                    => $this->email,
            'phone'                    => $this->phone,
        ];
    }
}
