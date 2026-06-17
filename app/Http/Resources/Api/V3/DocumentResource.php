<?php

namespace App\Http\Resources\Api\V3;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a Document model into the V3 API JSON representation.
 */
class DocumentResource extends JsonResource
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
            'id'                   => $this->id,
            'name'                 => $this->name,
            'type'                 => $this->type->value,
            'type_label'           => $this->type->label(),
            'stage'                => $this->stage->value,
            'google_drive_url'     => $this->google_drive_url,
            'is_uploaded_to_drive' => $this->google_drive_file_id !== null,
            'verified_at'          => $this->verified_at?->toISOString(),
            'created_at'           => $this->created_at->toISOString(),
        ];
    }
}
