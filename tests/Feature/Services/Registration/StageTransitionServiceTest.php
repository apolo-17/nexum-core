<?php

namespace Tests\Feature\Services\Registration;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use App\Models\StageTransition;
use App\Models\User;
use App\Services\Registration\StageTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature tests for StageTransitionService (requires database).
 *
 * Tests cover the full advance flow, rejection scenarios, helper methods,
 * and the audit trail written to stage_transitions.
 */
class StageTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private StageTransitionService $service;
    private User $notary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StageTransitionService::class);
        $this->notary  = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // advance() — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_advances_the_registration_to_the_next_stage(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::DATA_RECEIVED,
            'status' => RegistrationStatusEnum::ACTIVE,
        ]);

        $this->service->advance($registration, $this->notary);

        $this->assertSame(
            RegistrationStageEnum::IDENTITY_VALIDATION,
            $registration->fresh()->stage,
        );
    }

    #[Test]
    public function it_persists_an_immutable_stage_transition_record(): void
    {
        $registration = Registration::factory()->create([
            'stage' => RegistrationStageEnum::IDENTITY_VALIDATION,
        ]);

        $this->service->advance($registration, $this->notary, 'Identidad verificada');

        $this->assertDatabaseHas('stage_transitions', [
            'registration_id' => $registration->id,
            'from_stage'      => 'identity_validation',
            'to_stage'        => 'legal_name',
            'performed_by'    => $this->notary->id,
            'reason'          => 'Identidad verificada',
        ]);
    }

    #[Test]
    public function it_returns_the_created_stage_transition(): void
    {
        $registration = Registration::factory()->create([
            'stage' => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $transition = $this->service->advance($registration, $this->notary);

        $this->assertInstanceOf(StageTransition::class, $transition);
        $this->assertSame(RegistrationStageEnum::DATA_RECEIVED, $transition->from_stage);
        $this->assertSame(RegistrationStageEnum::IDENTITY_VALIDATION, $transition->to_stage);
    }

    #[Test]
    public function it_advances_through_each_stage_sequentially(): void
    {
        $registration = Registration::factory()->create([
            'stage' => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $ordered = RegistrationStageEnum::orderedStages();

        // Advance from DATA_RECEIVED through to EFIRMA_APPOINTMENT (7 advances total).
        foreach (array_slice($ordered, 0, 7) as $i => $stage) {
            $this->service->advance($registration, $this->notary);
            $this->assertSame($ordered[$i + 1], $registration->fresh()->stage);
        }
    }

    #[Test]
    public function it_sets_status_to_completed_when_reaching_the_terminal_stage(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::EFIRMA_APPOINTMENT,
            'status' => RegistrationStatusEnum::ACTIVE,
        ]);

        $this->service->advance($registration, $this->notary);

        $fresh = $registration->fresh();

        $this->assertSame(RegistrationStageEnum::COMPLETED, $fresh->stage);
        $this->assertSame(RegistrationStatusEnum::COMPLETED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    #[Test]
    public function it_accepts_a_null_reason(): void
    {
        $registration = Registration::factory()->create([
            'stage' => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $this->service->advance($registration, $this->notary, null);

        $this->assertDatabaseHas('stage_transitions', [
            'registration_id' => $registration->id,
            'reason'          => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // advance() — rejection scenarios
    // -------------------------------------------------------------------------

    #[Test]
    public function it_throws_when_the_registration_is_already_completed(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::COMPLETED,
            'status' => RegistrationStatusEnum::COMPLETED,
        ]);

        // Status check fires before the stage check — completed status blocks the advance.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/status is completed/');

        $this->service->advance($registration, $this->notary);
    }

    #[Test]
    public function it_throws_with_terminal_stage_message_when_active_but_no_next_stage(): void
    {
        // Edge case: status is active but stage is the terminal one (data inconsistency or forced state).
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::COMPLETED,
            'status' => RegistrationStatusEnum::ACTIVE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/terminal stage/');

        $this->service->advance($registration, $this->notary);
    }

    #[Test]
    public function it_throws_when_the_registration_is_cancelled(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::DATA_RECEIVED,
            'status' => RegistrationStatusEnum::CANCELLED,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/status is cancelled/');

        $this->service->advance($registration, $this->notary);
    }

    #[Test]
    public function it_throws_when_the_registration_is_on_hold(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::LEGAL_NAME,
            'status' => RegistrationStatusEnum::ON_HOLD,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/status is on_hold/');

        $this->service->advance($registration, $this->notary);
    }

    #[Test]
    public function it_does_not_write_a_transition_record_when_advance_is_rejected(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::COMPLETED,
            'status' => RegistrationStatusEnum::COMPLETED,
        ]);

        try {
            $this->service->advance($registration, $this->notary);
        } catch (RuntimeException) {
            // Expected — confirm no records were written.
        }

        $this->assertDatabaseCount('stage_transitions', 0);
    }

    // -------------------------------------------------------------------------
    // canAdvance() helper
    // -------------------------------------------------------------------------

    #[Test]
    public function can_advance_returns_true_for_an_active_non_terminal_registration(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::DATA_RECEIVED,
            'status' => RegistrationStatusEnum::ACTIVE,
        ]);

        $this->assertTrue($this->service->canAdvance($registration));
    }

    #[Test]
    public function can_advance_returns_false_for_a_completed_registration(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::COMPLETED,
            'status' => RegistrationStatusEnum::COMPLETED,
        ]);

        $this->assertFalse($this->service->canAdvance($registration));
    }

    #[Test]
    public function can_advance_returns_false_for_an_on_hold_registration(): void
    {
        $registration = Registration::factory()->create([
            'stage'  => RegistrationStageEnum::DATA_RECEIVED,
            'status' => RegistrationStatusEnum::ON_HOLD,
        ]);

        $this->assertFalse($this->service->canAdvance($registration));
    }

    // -------------------------------------------------------------------------
    // nextStage() / previousStage() helpers
    // -------------------------------------------------------------------------

    #[Test]
    public function next_stage_returns_the_stage_following_the_given_one(): void
    {
        $this->assertSame(
            RegistrationStageEnum::IDENTITY_VALIDATION,
            $this->service->nextStage(RegistrationStageEnum::DATA_RECEIVED),
        );
    }

    #[Test]
    public function next_stage_returns_null_when_at_completed(): void
    {
        $this->assertNull($this->service->nextStage(RegistrationStageEnum::COMPLETED));
    }

    #[Test]
    public function previous_stage_returns_the_stage_preceding_the_given_one(): void
    {
        $this->assertSame(
            RegistrationStageEnum::DATA_RECEIVED,
            $this->service->previousStage(RegistrationStageEnum::IDENTITY_VALIDATION),
        );
    }

    #[Test]
    public function previous_stage_returns_null_when_at_the_first_stage(): void
    {
        $this->assertNull($this->service->previousStage(RegistrationStageEnum::DATA_RECEIVED));
    }
}
