<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Note model instances in tests.
 *
 * Generates realistic internal notes that a notary team member would write.
 *
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Spanish note content pool representative of real notary workflow commentary.
     *
     * @var list<string>
     */
    private const CONTENT_POOL = [
        'Documentación revisada. Cliente informado del avance.',
        'Se solicitaron documentos adicionales para continuar el trámite.',
        'Cliente confirmó que enviará la información pendiente esta semana.',
        'Denominación social enviada al registro mercantil para dictamen.',
        'Se realizó llamada de seguimiento con el representante legal.',
        'Documentos apostillados recibidos correctamente.',
        'Pendiente confirmación de apertura bancaria por parte del cliente.',
        'RFC tramitado satisfactoriamente. Se notificó al cliente.',
        'Cita e.firma agendada. Recordatorio enviado al cliente.',
        'Acta constitutiva revisada y firmada por todas las partes.',
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
            'content'         => fake()->randomElement(self::CONTENT_POOL),
            'created_by'      => null,
            'is_pinned'       => false,
        ];
    }

    /**
     * Pin this note so it appears at the top of the timeline.
     *
     * @return static
     */
    public function pinned(): static
    {
        return $this->state(['is_pinned' => true]);
    }

    /**
     * Assign a creator to this note.
     *
     * @param  User  $user  Team member who wrote the note.
     * @return static
     */
    public function writtenBy(User $user): static
    {
        return $this->state(['created_by' => $user->id]);
    }
}
