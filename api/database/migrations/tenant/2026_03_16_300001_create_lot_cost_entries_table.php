<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_cost_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->string('cost_type'); // fruit, material, labor, overhead, transfer_in
            $table->string('description');
            $table->decimal('amount', 12, 4); // signed — negative for adjustments
            $table->decimal('quantity', 12, 4)->nullable(); // units consumed
            $table->decimal('unit_cost', 12, 4)->nullable(); // cost per unit
            $table->string('reference_type')->nullable(); // addition, work_order, purchase, manual, blend_allocation, split_allocation, bottling
            $table->uuid('reference_id')->nullable(); // FK to the source record
            $table->timestamp('performed_at');
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — cost entries are immutable (append-only)

            $table->index('lot_id');
            $table->index('cost_type');
            $table->index('reference_type');
            $table->index('performed_at');
            $table->index(['lot_id', 'cost_type']); // Cost breakdown queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_cost_entries');
    }
};
