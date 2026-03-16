<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dry_goods_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('item_type', 30); // bottle, cork, screw_cap, capsule, label_front, label_back, label_neck, carton, divider, tissue
            $table->string('unit_of_measure', 30); // each, sleeve, pallet
            $table->decimal('on_hand', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->nullable();
            $table->decimal('cost_per_unit', 10, 4)->nullable();
            $table->string('vendor_name', 200)->nullable();
            $table->uuid('vendor_id')->nullable(); // FK to vendors table when built
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('item_type', 'idx_dry_goods_items_type');
            $table->index('is_active', 'idx_dry_goods_items_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dry_goods_items');
    }
};
