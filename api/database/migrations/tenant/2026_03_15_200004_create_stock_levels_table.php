<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sku_id')->constrained('case_goods_skus')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('committed')->default(0);
            $table->timestamps();

            // Each SKU has exactly one stock level record per location
            $table->unique(['sku_id', 'location_id'], 'uq_stock_levels_sku_location');
            $table->index('location_id', 'idx_stock_levels_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
