<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ttb_report_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ttb_report_id');
            $table->string('part'); // I, II, III, IV, V
            $table->unsignedSmallInteger('line_number');
            $table->string('category');
            $table->string('wine_type'); // table, dessert, sparkling, special_natural, all
            $table->string('description');
            $table->decimal('gallons', 12, 1)->default(0);
            $table->jsonb('source_event_ids')->default('[]');
            $table->boolean('needs_review')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ttb_report_id')->references('id')->on('ttb_reports')->cascadeOnDelete();
            $table->index(['ttb_report_id', 'part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ttb_report_lines');
    }
};
