<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fermentation_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->unsignedSmallInteger('round_number'); // 1 = primary, 2 = ML, 3+ = re-inoculation
            $table->string('fermentation_type', 30); // primary, malolactic
            $table->date('inoculation_date');
            $table->string('yeast_strain', 100)->nullable();
            $table->string('ml_bacteria', 100)->nullable(); // ML fermentation only
            $table->decimal('target_temp', 6, 2)->nullable(); // °F
            $table->json('nutrients_schedule')->nullable();
            $table->string('status', 20)->default('active'); // active, completed, stuck
            $table->date('completion_date')->nullable();
            $table->date('confirmation_date')->nullable(); // ML dryness confirmation
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'fermentation_type']);
            $table->index(['lot_id', 'round_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fermentation_rounds');
    }
};
