<?php

namespace Database\Factories;

use App\Enums\ShareholderRoleEnum;
use App\Models\Registration;
use App\Models\Shareholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shareholder>
 */
class ShareholderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id'         => Registration::factory(),
            'name'                    => fake()->name(),
            'nationality'             => fake()->randomElement(['china', 'mexico', 'usa', 'canada']),
            'passport_number'         => null,
            'participation_percentage' => 100.00,
            'role'                    => ShareholderRoleEnum::SHAREHOLDER,
            'email'                   => fake()->safeEmail(),
            'phone'                   => null,
        ];
    }

    /**
     * Set the shareholder as legal representative.
     *
     * @return static
     */
    public function legalRepresentative(): static
    {
        return $this->state(['role' => ShareholderRoleEnum::LEGAL_REPRESENTATIVE]);
    }
}
