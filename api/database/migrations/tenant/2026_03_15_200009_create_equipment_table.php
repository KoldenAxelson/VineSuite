<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('equipment_type', 50); // tank, pump, press, filter, bottling_line, lab_instrument, forklift, other
            $table->string('serial_number', 100)->nullable();
            $table->string('manufacturer', 150)->nullable();
            $table->string('model_number', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 12, 2)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('status', 30)->default('operational'); // operational, maintenance, retired
            $table->date('next_maintenance_due')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('equipment_type');
            $table->index('status');
            $table->index('is_active');
            $table->index('next_maintenance_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
