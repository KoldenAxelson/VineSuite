<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role'); // e.g. cellar_hand, winemaker, forklift_operator
            $table->decimal('hourly_rate', 10, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('role');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labor_rates');
    }
};
