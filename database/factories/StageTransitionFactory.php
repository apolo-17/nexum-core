<?php

namespace Database\Factories;

use App\Enums\RegistrationStageEnum;
use App\Models\Registration;
use App\Models\StageTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating StageTransition model instances in tests.
 *
 * Defaults to the first manual advance: DATA_RECEIVED → IDENTITY_VALIDATION.
 * For the initial webhook arrival (null → DATA_RECEIVED), set from_stage to null explicitly.
 *
 * @extends Factory<StageTransition>
 */
class StageTransitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'from_stage'      => RegistrationStageEnum::DATA_RECEIVED,
            'to_stage'        => RegistrationStageEnum::IDENTITY_VALIDATION,
            'performed_by'    => null,
            'reason'          => 'Documentación revisada y validada.',
        ];
    }

    /**
     * Configure the transition as the initial webhook arrival (system-generated).
     *
     * @return static
     */
    public function initialArrival(): static
    {
        return $this->state([
            'from_stage'   => null,
            'to_stage'     => RegistrationStageEnum::DATA_RECEIVED,
            'performed_by' => null,
            'reason'       => 'Expediente recibido vía webhook del relay Singapur.',
        ]);
    }

    /**
     * Assign the user who performed this transition.
     *
     * @param  User  $user  Team member who advanced the stage.
     * @return static
     */
    public function performedBy(User $user): static
    {
        return $this->state(['performed_by' => $user->id]);
    }
}
