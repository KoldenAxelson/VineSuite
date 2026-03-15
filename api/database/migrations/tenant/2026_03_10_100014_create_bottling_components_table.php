<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottling_components', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('bottling_run_id')->constrained('bottling_runs')->cascadeOnDelete();

            // Component details
            $table->string('component_type'); // bottle, cork, capsule, label, carton
            $table->string('product_name'); // e.g. "750ml Bordeaux Green", "Natural Cork #9"
            $table->integer('quantity_used');
            $table->integer('quantity_wasted')->default(0);
            $table->string('unit')->default('each'); // each, roll, sheet

            // Inventory linkage (stubbed for 04-inventory.md)
            $table->uuid('inventory_item_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('bottling_run_id');
            $table->index('component_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottling_components');
    }
};
