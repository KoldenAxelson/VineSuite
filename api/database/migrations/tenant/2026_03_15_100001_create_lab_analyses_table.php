<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->date('test_date');
            $table->string('test_type', 50);
            $table->decimal('value', 12, 6);
            $table->string('unit', 30);
            $table->string('method', 100)->nullable();
            $table->string('analyst', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 30)->default('manual');
            $table->uuid('performed_by')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['lot_id', 'test_type', 'test_date']);
            $table->index(['test_type', 'test_date']);
            $table->index('test_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_analyses');
    }
};
