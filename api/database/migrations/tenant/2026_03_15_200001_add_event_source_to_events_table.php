<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add event_source column to the events table for module-level partitioning.
     *
     * The column labels where each event originates (production, lab, inventory, etc.)
     * and is auto-populated by EventLogger::resolveSource() — callers never set it directly.
     *
     * Existing events default to 'production'. A backfill updates Phase 3 lab events.
     * The immutability trigger must be temporarily disabled for the backfill UPDATE.
     *
     * See docs/references/event-source-partitioning.md for full design rationale.
     */
    public function up(): void
    {
        // Add column with default so existing rows are backfilled automatically
        Schema::table('events', function (Blueprint $table) {
            $table->string('event_source', 30)->default('production')->after('operation_type');
            $table->index('event_source', 'idx_events_source');
        });

        // Temporarily disable immutability trigger to backfill lab events
        DB::statement('ALTER TABLE events DISABLE TRIGGER events_immutability_guard');

        DB::statement("
            UPDATE events SET event_source = 'lab'
            WHERE operation_type IN (
                'lab_analysis_entered',
                'fermentation_round_created',
                'fermentation_data_entered',
                'fermentation_completed',
                'sensory_note_recorded'
            )
        ");

        // Re-enable immutability trigger
        DB::statement('ALTER TABLE events ENABLE TRIGGER events_immutability_guard');
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_source');
            $table->dropColumn('event_source');
        });
    }
};
