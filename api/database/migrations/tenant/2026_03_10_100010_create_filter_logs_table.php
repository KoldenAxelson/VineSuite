<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Lot being filtered
            $table->foreignUuid('lot_id')
                ->constrained('lots')
                ->cascadeOnDelete();

            // Optional vessel
            $table->foreignUuid('vessel_id')
                ->nullable()
                ->constrained('vessels')
                ->nullOnDelete();

            // Filter configuration
            $table->string('filter_type'); // pad, crossflow, cartridge, plate_and_frame, de, lenticular
            $table->string('filter_media')->nullable(); // specific media used (e.g., 0.45µm membrane, DE grade)
            $table->decimal('flow_rate_lph', 10, 2)->nullable(); // liters per hour
            $table->decimal('volume_processed_gallons', 12, 4); // total volume filtered

            // Fining details (optional — for fining operations)
            $table->string('fining_agent')->nullable(); // bentonite, gelatin, egg white, PVPP, etc.
            $table->decimal('fining_rate', 10, 4)->nullable(); // dosage rate
            $table->string('fining_rate_unit')->nullable(); // g/L, g/hL, mL/L, lb/1000gal
            $table->text('bench_trial_notes')->nullable(); // bench trial results and observations
            $table->text('treatment_notes')->nullable(); // final treatment notes

            // Pre/post analysis references (nullable UUIDs — lab module not yet built)
            $table->uuid('pre_analysis_id')->nullable();
            $table->uuid('post_analysis_id')->nullable();

            // Who and when
            $table->foreignUuid('performed_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('performed_at');

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('lot_id');
            $table->index('filter_type');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_logs');
    }
};
