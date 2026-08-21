<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHY a soldado's FIEL was taken out of the MUA rotation.
 *
 * The SE blocks a whole account for reasons that belong to the account, not to
 * the denomination: it is already at the one-in-process cap, or it refuses the
 * certificate/password. Before this, such a FIEL kept looking free — Laravel
 * only counted the denominations IT created — so every dispatch picked it again
 * and bounced. The bot now reports those as `deferred` and we park the FIEL
 * here, with the portal's own wording so an operator knows what to fix.
 */
return new class extends Migration
{
    /**
     * Add the block reason/timestamp columns.
     */
    public function up(): void
    {
        Schema::table('soldados', function (Blueprint $table): void {
            $table->text('mua_blocked_reason')
                ->nullable()
                ->after('available_for_mua')
                ->comment('Portal wording explaining why this FIEL left the MUA rotation.');

            $table->timestamp('mua_blocked_at')
                ->nullable()
                ->after('mua_blocked_reason')
                ->comment('When the FIEL was parked; cleared when an operator re-enables it.');
        });
    }

    /**
     * Drop the block reason/timestamp columns.
     */
    public function down(): void
    {
        Schema::table('soldados', function (Blueprint $table): void {
            $table->dropColumn(['mua_blocked_reason', 'mua_blocked_at']);
        });
    }
};
