<?php

namespace Database\Factories;

use App\Enums\WebhookEventStatusEnum;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for generating WebhookEvent test fixtures.
 *
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    /**
     * @var class-string<WebhookEvent>
     */
    protected $model = WebhookEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt-' . $this->faker->unique()->uuid(),
            'source'   => 'singapur_relay',
            'payload'  => [],
            'status'   => WebhookEventStatusEnum::PENDING,
        ];
    }
}
