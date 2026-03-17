<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_cogs_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->decimal('total_fruit_cost', 12, 4)->default(0);
            $table->decimal('total_material_cost', 12, 4)->default(0);
            $table->decimal('total_labor_cost', 12, 4)->default(0);
            $table->decimal('total_overhead_cost', 12, 4)->default(0);
            $table->decimal('total_transfer_in_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->decimal('volume_gallons_at_calc', 10, 4);
            $table->decimal('cost_per_gallon', 10, 4)->nullable();
            $table->integer('bottles_produced')->nullable();
            $table->decimal('cost_per_bottle', 10, 4)->nullable();
            $table->decimal('cost_per_case', 10, 4)->nullable();
            $table->decimal('packaging_cost_per_bottle', 10, 4)->nullable();
            $table->decimal('bottling_labor_cost', 12, 4)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — COGS summaries are immutable snapshots
            $table->index('lot_id');
            $table->index('calculated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_cogs_summaries');
    }
};
