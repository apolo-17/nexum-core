<?php

namespace Database\Factories;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalName>
 */
class LegalNameFactory extends Factory
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
            'name'            => fake()->company() . ' SA de CV',
            'priority'        => 1,
            'status'          => LegalNameStatusEnum::WAIT,
        ];
    }

    /**
     * Set the denomination as approved.
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state([
            'status'                   => LegalNameStatusEnum::APPROVED,
            'clave_unica_denominacion' => strtoupper(fake()->lexify('???-??????')),
            'authorization_timestamp'  => now(),
        ]);
    }
}
