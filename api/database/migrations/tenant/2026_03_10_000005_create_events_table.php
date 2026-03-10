<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped events table — the core append-only event log.
     *
     * Architecture doc Section 3: All winery operations are recorded as immutable events.
     * No UPDATE or DELETE — only INSERT. Corrections are new events (e.g., addition_corrected).
     * Idempotency key prevents duplicate submissions from offline mobile sync retries.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 50);           // 'lot', 'vessel', 'barrel', 'inventory_item', 'order'
            $table->uuid('entity_id');
            $table->string('operation_type', 50);         // 'addition', 'transfer', 'rack', 'bottle', 'blend', 'sale'
            $table->jsonb('payload');                      // all operation-specific data
            $table->uuid('performed_by')->nullable();     // user who performed the operation
            $table->timestampTz('performed_at');          // client timestamp (may be from offline device)
            $table->timestampTz('synced_at')->nullable(); // server receipt time (null for locally-created events)
            $table->string('device_id', 100)->nullable(); // identifies which client submitted
            $table->string('idempotency_key', 100)->unique()->nullable(); // prevents duplicate event submission
            $table->timestampTz('created_at')->useCurrent();

            // Foreign key to users — nullable for system-generated events
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();

            // Indexes per architecture doc
            $table->index(['entity_type', 'entity_id'], 'idx_events_entity');
            $table->index('operation_type', 'idx_events_operation');
        });

        // BRIN index for time-series queries on performed_at (per spec gotcha)
        // BRIN indexes are much smaller than B-tree for sequential/time-ordered data
        DB::statement('CREATE INDEX idx_events_performed_at ON events USING BRIN (performed_at)');

        // Immutability: create a trigger that prevents UPDATE and DELETE on events table.
        // This is the database-level enforcement of "events are never updated or deleted".
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_event_mutation() RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'Events table is immutable. UPDATE and DELETE operations are not allowed. Use correcting events instead.';
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('
            CREATE TRIGGER events_immutability_guard
            BEFORE UPDATE OR DELETE ON events
            FOR EACH ROW
            EXECUTE FUNCTION prevent_event_mutation();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS events_immutability_guard ON events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_event_mutation()');
        Schema::dropIfExists('events');
    }
};
