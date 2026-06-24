<?php

namespace Database\Factories;

use App\Enums\LegalAgentTypeEnum;
use App\Models\LegalAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalAgent>
 */
class LegalAgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(LegalAgentTypeEnum::cases()),
            'name' => fake()->name(),
            'nationality' => 'mexicana',
            'rfc' => strtoupper(fake()->bothify('????######???')),
            'curp' => strtoupper(fake()->bothify('????######??????##')),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('55########'),
            'birthdate' => fake()->dateTimeBetween('-65 years', '-25 years')->format('Y-m-d'),
            'birthplace' => 'Ciudad de México, México',
            'address' => fake()->streetAddress().', CDMX',
            'notes' => null,
            'is_active' => true,
        ];
    }

    /**
     * Configure the agent as a legal representative.
     */
    public function legalRepresentative(): static
    {
        return $this->state(['type' => LegalAgentTypeEnum::LEGAL_REPRESENTATIVE]);
    }

    /**
     * Configure the agent as a commissary.
     */
    public function commissary(): static
    {
        return $this->state(['type' => LegalAgentTypeEnum::COMMISSARY]);
    }
}
