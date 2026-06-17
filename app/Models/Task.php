<?php

namespace App\Models;

use App\Enums\TaskPriorityEnum;
use App\Enums\TaskTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an actionable item tied to a registration expedient.
 *
 * Tasks can be created manually by team members or automatically by system processes.
 * When a manual task gets automated in the future, its type changes to AUTOMATED
 * and automated_by records the responsible service — the historical record is preserved.
 */
class Task extends Model
{
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'title',
        'description',
        'priority',
        'type',
        'automated_by',
        'due_date',
        'assigned_to',
        'created_by',
        'completed_at',
        'completed_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority'     => TaskPriorityEnum::class,
            'type'         => TaskTypeEnum::class,
            'due_date'     => 'date',
            'completed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this task belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the user this task is assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who created this task.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who marked this task as completed.
     *
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this task has been completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
