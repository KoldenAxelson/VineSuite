<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignUuid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->string('addition_type');
            $table->string('product_name');
            $table->decimal('rate', 12, 4)->nullable();
            $table->string('rate_unit')->nullable();
            $table->decimal('total_amount', 12, 4);
            $table->string('total_unit');
            $table->text('reason')->nullable();
            $table->foreignUuid('performed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('performed_at');
            $table->foreignUuid('inventory_item_id')->nullable();
            $table->timestamps();

            $table->index('addition_type');
            $table->index('product_name');
            $table->index(['lot_id', 'addition_type']);
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additions');
    }
};
