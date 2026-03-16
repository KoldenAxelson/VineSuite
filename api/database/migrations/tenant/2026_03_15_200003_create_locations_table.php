<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('address', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'idx_locations_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
