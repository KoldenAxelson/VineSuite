<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Case goods SKU registry — bottled wine products available for sale.
     *
     * SKUs represent finished products (bottles/cases). They can be auto-created
     * from bottling runs or manually entered for purchased finished wine.
     *
     * See docs/execution/tasks/04-inventory.md Sub-Task 1.
     */
    public function up(): void
    {
        Schema::create('case_goods_skus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wine_name');                          // e.g., "2022 Estate Cabernet Sauvignon"
            $table->unsignedSmallInteger('vintage');              // 2022, 2023, etc.
            $table->string('varietal', 100);                      // Cabernet Sauvignon, Pinot Noir, etc.
            $table->string('format', 20)->default('750ml');       // 750ml, 375ml, 1.5L, etc.
            $table->unsignedSmallInteger('case_size')->default(12); // bottles per case (6 or 12)
            $table->string('upc_barcode', 50)->nullable();        // UPC/EAN barcode
            $table->decimal('price', 10, 2)->nullable();          // default retail price
            $table->decimal('cost_per_bottle', 10, 2)->nullable(); // COGS — populated by cost accounting module
            $table->boolean('is_active')->default(true);
            $table->string('image_path')->nullable();             // product photo (local filesystem)
            $table->text('tasting_notes')->nullable();
            $table->string('tech_sheet_path')->nullable();        // tech sheet PDF (local filesystem)
            $table->uuid('lot_id')->nullable();                   // origin lot for traceability
            $table->uuid('bottling_run_id')->nullable();          // bottling run that created this SKU
            $table->timestamps();

            // Foreign keys — nullable for manually created SKUs
            $table->foreign('lot_id')->references('id')->on('lots')->nullOnDelete();
            $table->foreign('bottling_run_id')->references('id')->on('bottling_runs')->nullOnDelete();

            // Indexes for common filters
            $table->index('vintage', 'idx_skus_vintage');
            $table->index('varietal', 'idx_skus_varietal');
            $table->index('is_active', 'idx_skus_active');
            $table->index('format', 'idx_skus_format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_goods_skus');
    }
};
