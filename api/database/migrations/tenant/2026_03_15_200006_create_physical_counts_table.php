<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress'); // in_progress, completed, cancelled
            $table->uuid('started_by');
            $table->timestamp('started_at');
            $table->uuid('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('location_id', 'idx_physical_counts_location');
            $table->index('status', 'idx_physical_counts_status');
            $table->index('started_at', 'idx_physical_counts_started_at');
        });

        Schema::create('physical_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('physical_count_id')->constrained('physical_counts')->cascadeOnDelete();
            $table->foreignUuid('sku_id')->constrained('case_goods_skus')->cascadeOnDelete();
            $table->integer('system_quantity'); // snapshot of on_hand at count start
            $table->integer('counted_quantity')->nullable(); // actual count entered by user
            $table->integer('variance')->nullable(); // counted - system (computed on save)
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['physical_count_id', 'sku_id'], 'uq_physical_count_lines_count_sku');
            $table->index('sku_id', 'idx_physical_count_lines_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_count_lines');
        Schema::dropIfExists('physical_counts');
    }
};
