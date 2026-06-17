<?php

namespace App\Http\Resources\Api\V3;

use App\Enums\RegistrationStageEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms a Registration model into the V3 API JSON representation.
 *
 * Includes nested shareholders, legal names, and documents so the relay can
 * render a complete status view for the Chinese client in a single request.
 */
class RegistrationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orderedStages = RegistrationStageEnum::orderedStages();
        $currentIndex  = array_search($this->stage, $orderedStages, true);

        return [
            'id'                   => $this->id,
            'singapur_client_code' => $this->singapur_client_code,

            // Current state
            'stage'                => $this->stage->value,
            'stage_label'          => $this->stage->label(),
            'stage_progress'       => $currentIndex !== false ? $currentIndex + 1 : 1,
            'stage_total'          => count($orderedStages),
            'status'               => $this->status->value,

            // Company data
            'company_type'         => $this->company_type,
            'company_name'         => $this->legalNames->where('priority', 1)->first()?->name,
            'rfc'                  => $this->rfc,
            'efirma_appointment_at' => $this->efirma_appointment_at?->toISOString(),

            // Related entities — always eager loaded by the controller
            'shareholders'         => ShareholderResource::collection($this->whenLoaded('shareholders')),
            'legal_names'          => LegalNameResource::collection($this->whenLoaded('legalNames')),
            'documents'            => DocumentResource::collection($this->whenLoaded('documents')),

            // Timestamps
            'completed_at'         => $this->completed_at?->toISOString(),
            'created_at'           => $this->created_at->toISOString(),
            'updated_at'           => $this->updated_at->toISOString(),
        ];
    }
}
