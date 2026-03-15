<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensory_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignUuid('taster_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('rating', 5, 2)->nullable();
            $table->string('rating_scale', 20)->default('five_point');
            $table->text('nose_notes')->nullable();
            $table->text('palate_notes')->nullable();
            $table->text('overall_notes')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'date']);
            $table->index(['taster_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensory_notes');
    }
};
