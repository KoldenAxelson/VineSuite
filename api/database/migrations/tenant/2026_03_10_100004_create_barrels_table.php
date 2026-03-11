<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barrels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_id')
                ->constrained('vessels')
                ->cascadeOnDelete();
            $table->string('cooperage')->nullable();
            $table->string('toast_level')->nullable(); // light|medium|medium_plus|heavy
            $table->string('oak_type')->nullable();    // french|american|hungarian|other
            $table->string('forest_origin')->nullable();
            $table->decimal('volume_gallons', 12, 4)->default(59.4300); // standard barrel ~59.43 gal
            $table->unsignedInteger('years_used')->default(0);
            $table->string('qr_code')->nullable();
            $table->timestamps();

            // Indexes for common filters
            $table->index('cooperage');
            $table->index('oak_type');
            $table->index('toast_level');
            $table->index('years_used');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barrels');
    }
};
