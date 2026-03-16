<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sku_id')->constrained('case_goods_skus')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('movement_type', 30); // received, sold, transferred, adjusted, returned, bottled
            $table->integer('quantity'); // positive = in, negative = out
            $table->string('reference_type', 50)->nullable(); // order, bottling_run, transfer, adjustment
            $table->uuid('reference_id')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('performed_at');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sku_id', 'idx_stock_movements_sku');
            $table->index('location_id', 'idx_stock_movements_location');
            $table->index('movement_type', 'idx_stock_movements_type');
            $table->index('performed_at', 'idx_stock_movements_performed_at');
            $table->index(['reference_type', 'reference_id'], 'idx_stock_movements_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
