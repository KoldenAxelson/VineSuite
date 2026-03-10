<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped activity log — immutable audit trail.
     *
     * Tracks system-level changes: user edited a profile, changed a setting, etc.
     * Separate from the event log (which tracks winery operations like additions/transfers).
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();         // who performed the action (null for system)
            $table->string('action', 20);                 // 'created', 'updated', 'deleted'
            $table->string('model_type', 100);            // e.g., 'App\Models\User', 'App\Models\WineryProfile'
            $table->uuid('model_id');                     // UUID of the affected model
            $table->jsonb('old_values')->nullable();      // previous values (null for create)
            $table->jsonb('new_values')->nullable();      // new values (null for delete)
            $table->jsonb('changed_fields')->nullable();  // array of field names that changed
            $table->string('ip_address', 45)->nullable(); // request IP
            $table->string('user_agent')->nullable();     // browser/device info
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['model_type', 'model_id'], 'idx_activity_model');
            $table->index('user_id', 'idx_activity_user');
            $table->index('action', 'idx_activity_action');
        });

        // BRIN index for time-series queries
        DB::statement('CREATE INDEX idx_activity_created_at ON activity_logs USING BRIN (created_at)');

        // Immutability: prevent UPDATE and DELETE
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_activity_log_mutation() RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'Activity logs are immutable. UPDATE and DELETE operations are not allowed.';
                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER activity_logs_immutability_guard
            BEFORE UPDATE OR DELETE ON activity_logs
            FOR EACH ROW
            EXECUTE FUNCTION prevent_activity_log_mutation();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS activity_logs_immutability_guard ON activity_logs');
        DB::statement('DROP FUNCTION IF EXISTS prevent_activity_log_mutation()');
        Schema::dropIfExists('activity_logs');
    }
};
