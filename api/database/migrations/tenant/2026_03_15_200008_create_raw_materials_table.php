<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('category', 30); // additive, yeast, nutrient, fining_agent, acid, enzyme, oak_alternative
            $table->string('unit_of_measure', 30); // g, kg, L, each
            $table->decimal('on_hand', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->nullable();
            $table->decimal('cost_per_unit', 10, 4)->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('vendor_name', 200)->nullable();
            $table->uuid('vendor_id')->nullable(); // FK to vendors table when built
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('category', 'idx_raw_materials_category');
            $table->index('is_active', 'idx_raw_materials_active');
            $table->index('expiration_date', 'idx_raw_materials_expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_materials');
    }
};
