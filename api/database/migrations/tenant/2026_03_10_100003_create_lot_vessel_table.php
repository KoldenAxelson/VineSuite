<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_vessel', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lot_id');
            $table->uuid('vessel_id');
            $table->decimal('volume_gallons', 12, 4);
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('emptied_at')->nullable();
            $table->timestamps();

            $table->foreign('lot_id')->references('id')->on('lots')->cascadeOnDelete();
            $table->foreign('vessel_id')->references('id')->on('vessels')->cascadeOnDelete();

            // Index for finding current contents (where emptied_at IS NULL)
            $table->index(['vessel_id', 'emptied_at']);
            $table->index(['lot_id', 'emptied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_vessel');
    }
};
