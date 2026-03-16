<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('maintenance_type', 50); // cleaning, cip, calibration, repair, inspection, preventive
            $table->date('performed_date');
            $table->uuid('performed_by')->nullable();
            $table->text('description')->nullable();
            $table->text('findings')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->date('next_due_date')->nullable();
            $table->boolean('passed')->nullable(); // for calibration/inspection: true = passed, false = failed, null = N/A
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('equipment_id');
            $table->index('maintenance_type');
            $table->index('performed_date');
            $table->index('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
    }
};
