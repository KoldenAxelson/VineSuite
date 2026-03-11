<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type'); // tank, barrel, flexitank, tote, demijohn, concrete_egg, amphora
            $table->decimal('capacity_gallons', 12, 4);
            $table->string('material')->nullable(); // stainless, oak, concrete, etc.
            $table->string('location')->nullable(); // physical location in winery
            $table->string('status')->default('empty'); // in_use, empty, cleaning, out_of_service
            $table->date('purchase_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('status');
            $table->index('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
