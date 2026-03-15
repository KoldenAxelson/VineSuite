<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blend_trials', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name'); // "2024 Reserve Blend Trial #1"
            $table->string('status')->default('draft'); // draft, finalized, archived
            $table->integer('version')->default(1); // for comparing multiple trial versions

            // Variety composition calculated from components
            $table->jsonb('variety_composition')->nullable(); // { "Cabernet Sauvignon": 65.0, "Merlot": 25.0, "Petit Verdot": 10.0 }
            $table->string('ttb_label_variety')->nullable(); // Dominant variety if >=75%, null if blend
            $table->decimal('total_volume_gallons', 12, 4)->nullable(); // Calculated total volume

            // Resulting lot (set on finalization)
            $table->uuid('resulting_lot_id')->nullable();

            // Who and when
            $table->foreignUuid('created_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blend_trials');
    }
};
