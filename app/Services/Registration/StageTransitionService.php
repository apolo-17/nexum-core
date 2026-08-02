<?php

namespace App\Services\Registration;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use App\Models\StageTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enforces the registration stage state machine and records every transition.
 *
 * Stages advance sequentially following RegistrationStageEnum::orderedStages().
 * Skipping stages, reversing, and advancing non-active registrations are all rejected.
 * Every allowed transition is persisted in stage_transitions as an immutable audit record.
 */
class StageTransitionService
{
    /**
     * Advance a registration to the next sequential stage.
     *
     * Validates that the registration is active and that a next stage exists,
     * then persists the transition and updates the registration in one transaction.
     *
     * @param  Registration  $registration  The expedient to advance.
     * @param  User          $performedBy   The user performing the advance.
     * @param  string|null   $reason        Optional note explaining the advance.
     * @return StageTransition              The immutable transition record.
     *
     * @throws RuntimeException When the registration cannot be advanced.
     */
    public function advance(
        Registration $registration,
        User $performedBy,
        ?string $reason = null,
    ): StageTransition {
        $this->assertCanAdvance($registration);

        $fromStage = $registration->stage;
        $toStage   = $this->nextStage($fromStage);

        return DB::transaction(function () use ($registration, $performedBy, $fromStage, $toStage, $reason): StageTransition {
            $transition = StageTransition::create([
                'registration_id' => $registration->id,
                'from_stage'      => $fromStage,
                'to_stage'        => $toStage,
                'performed_by'    => $performedBy->id,
                'reason'          => $reason,
            ]);

            // Mark as completed when reaching the terminal stage.
            $updates = ['stage' => $toStage];

            if ($toStage === RegistrationStageEnum::COMPLETED) {
                $updates['status']       = RegistrationStatusEnum::COMPLETED;
                $updates['completed_at'] = now();
            }

            $registration->update($updates);

            return $transition;
        });
    }

    /**
     * Advance a registration forward to a target stage, recording each step.
     *
     * Moves sequentially from the current stage up to (and including) the target, writing an
     * immutable StageTransition for every hop. Forward-only: if the target is at or before the
     * current stage, nothing happens (never reverses). Attributed to $performedBy, or to the
     * system (performed_by = null) when none is given — used by the manual intake tooling to
     * reflect that an expediente arrived already constituted.
     *
     * @param  Registration  $registration  The expedient to advance.
     * @param  RegistrationStageEnum  $target  The stage to reach.
     * @param  User|null  $performedBy  Who performed it, or null for a system transition.
     * @param  string|null  $reason  Optional note stored on every transition.
     * @return list<array{from:?string,to:string}> The hops applied (empty if already at/after target).
     *
     * @throws RuntimeException When the registration is not active.
     */
    public function jumpTo(
        Registration $registration,
        RegistrationStageEnum $target,
        ?User $performedBy = null,
        ?string $reason = null,
    ): array {
        if ($registration->status !== RegistrationStatusEnum::ACTIVE) {
            throw new RuntimeException(
                "Cannot advance registration {$registration->singapur_client_code}: "
                . "status is {$registration->status->value}, expected active."
            );
        }

        $ordered = RegistrationStageEnum::orderedStages();
        $targetIndex = array_search($target, $ordered, true);
        if ($targetIndex === false) {
            throw new RuntimeException("Unknown target stage {$target->value}.");
        }

        $hops = [];

        return DB::transaction(function () use ($registration, $ordered, $targetIndex, $performedBy, $reason, &$hops): array {
            while (true) {
                $currentIndex = array_search($registration->stage, $ordered, true);
                if ($currentIndex === false || $currentIndex >= $targetIndex) {
                    break;
                }

                $fromStage = $registration->stage;
                $toStage = $ordered[$currentIndex + 1];

                StageTransition::create([
                    'registration_id' => $registration->id,
                    'from_stage' => $fromStage,
                    'to_stage' => $toStage,
                    'performed_by' => $performedBy?->id,
                    'reason' => $reason,
                ]);

                $updates = ['stage' => $toStage];
                if ($toStage === RegistrationStageEnum::COMPLETED) {
                    $updates['status'] = RegistrationStatusEnum::COMPLETED;
                    $updates['completed_at'] = now();
                }
                $registration->update($updates);

                $hops[] = ['from' => $fromStage->value, 'to' => $toStage->value];
            }

            return $hops;
        });
    }

    /**
     * Determine whether a registration is eligible for stage advancement.
     *
     * Does not throw — safe to use in UI conditionals (e.g., to show/hide a button).
     *
     * @param  Registration  $registration  The expedient to check.
     * @return bool
     */
    public function canAdvance(Registration $registration): bool
    {
        if ($registration->status !== RegistrationStatusEnum::ACTIVE) {
            return false;
        }

        return $this->nextStage($registration->stage) !== null;
    }

    /**
     * Return the next sequential stage after the given one, or null if already at the end.
     *
     * @param  RegistrationStageEnum  $current  The current stage.
     * @return RegistrationStageEnum|null
     */
    public function nextStage(RegistrationStageEnum $current): ?RegistrationStageEnum
    {
        $ordered = RegistrationStageEnum::orderedStages();
        $index   = array_search($current, $ordered, true);

        if ($index === false) {
            return null;
        }

        return $ordered[$index + 1] ?? null;
    }

    /**
     * Return the previous sequential stage before the given one, or null if at the start.
     *
     * @param  RegistrationStageEnum  $current  The current stage.
     * @return RegistrationStageEnum|null
     */
    public function previousStage(RegistrationStageEnum $current): ?RegistrationStageEnum
    {
        $ordered = RegistrationStageEnum::orderedStages();
        $index   = array_search($current, $ordered, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $ordered[$index - 1];
    }

    /**
     * Assert that the registration can be advanced, throwing if it cannot.
     *
     * @param  Registration  $registration  The expedient to validate.
     * @return void
     *
     * @throws RuntimeException When the status is not ACTIVE or the stage is terminal.
     */
    private function assertCanAdvance(Registration $registration): void
    {
        if ($registration->status !== RegistrationStatusEnum::ACTIVE) {
            throw new RuntimeException(
                "Cannot advance registration {$registration->singapur_client_code}: "
                . "status is {$registration->status->value}, expected active."
            );
        }

        if ($this->nextStage($registration->stage) === null) {
            throw new RuntimeException(
                "Cannot advance registration {$registration->singapur_client_code}: "
                . "already at terminal stage {$registration->stage->value}."
            );
        }
    }
}
