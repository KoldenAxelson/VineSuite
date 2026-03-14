<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('press_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Parent lot being pressed
            $table->foreignUuid('lot_id')
                ->constrained('lots')
                ->cascadeOnDelete();

            // Optional vessel where pressing takes place
            $table->foreignUuid('vessel_id')
                ->nullable()
                ->constrained('vessels')
                ->nullOnDelete();

            // Press configuration
            $table->string('press_type'); // basket, bladder, pneumatic, manual
            $table->decimal('fruit_weight_kg', 12, 4); // weight of fruit/must going into press
            $table->decimal('total_juice_gallons', 12, 4); // total juice yield

            // Press fractions — JSONB array of objects
            // Each: { "fraction": "free_run|light_press|heavy_press", "volume_gallons": 50.0, "child_lot_id": null }
            $table->jsonb('fractions');

            // Yield calculation (stored, computed at creation)
            $table->decimal('yield_percent', 8, 4); // juice yield as % of fruit weight

            // Pomace disposal
            $table->decimal('pomace_weight_kg', 12, 4)->nullable();
            $table->string('pomace_destination')->nullable(); // compost, vineyard, disposal, sold

            // Who and when
            $table->foreignUuid('performed_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('performed_at');

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('lot_id');
            $table->index('press_type');
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('press_logs');
    }
};
