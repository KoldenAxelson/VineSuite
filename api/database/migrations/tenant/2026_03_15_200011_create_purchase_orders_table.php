<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('vendor_name', 200);
            $table->uuid('vendor_id')->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 30)->default('ordered'); // ordered, partial, received, cancelled
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('order_date');
            $table->index('vendor_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
