<?php

namespace Database\Factories;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskTypeEnum;
use App\Models\Registration;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Task model instances in tests.
 *
 * Defaults to a pending manual task with no assignee or due date.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Task title pool representative of real notary workflow actions.
     *
     * @var list<string>
     */
    private const TITLE_POOL = [
        'Verificar identidad del representante legal',
        'Solicitar documentos faltantes al cliente',
        'Revisar acta constitutiva con el notario',
        'Enviar denominación social a la SE',
        'Confirmar apertura de cuenta bancaria',
        'Tramitar RFC ante el SAT',
        'Agendar cita e.firma en el SAT',
        'Subir comprobante bancario a Drive',
        'Revisar pasaporte del accionista',
        'Confirmar recepción de documentos por el cliente',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'title'           => fake()->randomElement(self::TITLE_POOL),
            'description'     => null,
            'priority'        => fake()->randomElement(TaskPriorityEnum::cases()),
            'type'            => TaskTypeEnum::MANUAL,
            'automated_by'    => null,
            'due_date'        => fake()->optional(0.6)->dateTimeBetween('now', '+30 days'),
            'assigned_to'     => null,
            'created_by'      => null,
            'completed_at'    => null,
            'completed_by'    => null,
        ];
    }

    /**
     * Mark this task as completed.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state([
            'completed_at' => now()->subDays(fake()->numberBetween(1, 15)),
        ]);
    }

    /**
     * Set this task to high priority.
     *
     * @return static
     */
    public function highPriority(): static
    {
        return $this->state(['priority' => TaskPriorityEnum::HIGH]);
    }

    /**
     * Assign this task to a specific team member.
     *
     * @param  User  $user  Team member responsible for completing the task.
     * @return static
     */
    public function assignedTo(User $user): static
    {
        return $this->state(['assigned_to' => $user->id]);
    }
}
