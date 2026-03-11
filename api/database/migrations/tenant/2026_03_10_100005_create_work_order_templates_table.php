<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('operation_type');
            $table->text('default_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('operation_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_templates');
    }
};
