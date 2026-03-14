<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignUuid('from_vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->foreignUuid('to_vessel_id')->constrained('vessels')->cascadeOnDelete();
            $table->decimal('volume_gallons', 12, 4);
            $table->string('transfer_type');
            $table->decimal('variance_gallons', 12, 4)->default(0);
            $table->foreignUuid('performed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('performed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('lot_id');
            $table->index(['from_vessel_id', 'performed_at']);
            $table->index(['to_vessel_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
