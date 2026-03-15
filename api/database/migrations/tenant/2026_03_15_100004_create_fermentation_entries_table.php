<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fermentation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fermentation_round_id')->constrained('fermentation_rounds')->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('temperature', 6, 2)->nullable(); // °F
            $table->decimal('brix_or_density', 10, 4)->nullable(); // Brix or SG
            $table->string('measurement_type', 20)->nullable(); // brix, specific_gravity
            $table->decimal('free_so2', 8, 2)->nullable(); // mg/L
            $table->text('notes')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamps();

            $table->index(['fermentation_round_id', 'entry_date']);
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fermentation_entries');
    }
};
