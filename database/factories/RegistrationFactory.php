<?php

namespace Database\Factories;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 1;

        return [
            'singapur_client_code' => str_pad((string) $sequence++, 6, '0', STR_PAD_LEFT),
            'singapur_package_id'  => fake()->uuid(),
            'company_type'         => fake()->randomElement(['SA de CV', 'SRL de CV', 'SAPI de CV']),
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
            'status'               => RegistrationStatusEnum::ACTIVE,
        ];
    }

    /**
     * Set a specific stage for the registration.
     *
     * @param  RegistrationStageEnum  $stage
     * @return static
     */
    public function atStage(RegistrationStageEnum $stage): static
    {
        return $this->state(['stage' => $stage]);
    }

    /**
     * Put the registration on hold.
     *
     * @return static
     */
    public function onHold(): static
    {
        return $this->state(['status' => RegistrationStatusEnum::ON_HOLD]);
    }
}
