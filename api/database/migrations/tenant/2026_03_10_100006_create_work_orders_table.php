<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation_type');
            $table->foreignUuid('lot_id')->nullable()->constrained('lots')->nullOnDelete();
            $table->foreignUuid('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending'); // pending|in_progress|completed|skipped
            $table->string('priority')->default('normal'); // low|normal|high
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_notes')->nullable();
            $table->foreignUuid('template_id')->nullable()->constrained('work_order_templates')->nullOnDelete();
            $table->timestamps();

            $table->index('operation_type');
            $table->index('status');
            $table->index('priority');
            $table->index('due_date');
            $table->index('assigned_to');
            $table->index(['status', 'due_date']); // Calendar/list queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
