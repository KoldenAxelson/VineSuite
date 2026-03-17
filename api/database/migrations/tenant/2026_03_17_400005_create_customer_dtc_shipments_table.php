<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_dtc_shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('customer_id'); // External customer reference
            $table->string('state_code', 2);
            $table->string('order_id')->nullable();
            $table->decimal('cases_shipped', 10, 2)->default(0);
            $table->decimal('gallons_shipped', 10, 1)->default(0);
            $table->timestamp('shipped_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'state_code']);
            $table->index('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_dtc_shipments');
    }
};
