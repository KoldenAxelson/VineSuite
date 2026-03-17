<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ttb_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('report_period_month');
            $table->unsignedSmallInteger('report_period_year');
            $table->string('status')->default('draft'); // draft, reviewed, filed, amended
            $table->timestamp('generated_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->jsonb('data')->nullable(); // Full report payload for historical reference
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['report_period_month', 'report_period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ttb_reports');
    }
};
