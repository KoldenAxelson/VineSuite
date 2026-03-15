<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blend_trial_components', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('blend_trial_id')
                ->constrained('blend_trials')
                ->cascadeOnDelete();

            $table->foreignUuid('source_lot_id')
                ->constrained('lots')
                ->cascadeOnDelete();

            $table->decimal('percentage', 8, 4); // 65.0000 = 65%
            $table->decimal('volume_gallons', 12, 4); // actual volume from this lot

            $table->timestamps();

            // Indexes
            $table->index('blend_trial_id');
            $table->index('source_lot_id');
            $table->unique(['blend_trial_id', 'source_lot_id']); // one entry per lot per trial
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blend_trial_components');
    }
};
