<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bottling_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Source lot
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();

            // Bottling details
            $table->string('bottle_format'); // 750ml, 375ml, 1.5L, etc.
            $table->integer('bottles_filled');
            $table->integer('bottles_breakage')->default(0);
            $table->decimal('waste_percent', 5, 2)->default(0); // % of volume lost
            $table->decimal('volume_bottled_gallons', 12, 4); // total volume consumed from lot
            $table->string('status')->default('planned'); // planned, in_progress, completed

            // Case goods output (populated on completion)
            $table->string('sku')->nullable(); // auto-generated or user-provided
            $table->integer('cases_produced')->nullable(); // bottles / bottles_per_case
            $table->integer('bottles_per_case')->default(12);

            // Who and when
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bottled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('lot_id');
            $table->index('status');
            $table->index('bottled_at');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bottling_runs');
    }
};
