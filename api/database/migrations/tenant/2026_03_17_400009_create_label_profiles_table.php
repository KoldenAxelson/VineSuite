<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blend_trial_id')->nullable()->constrained('blend_trials')->nullOnDelete();
            $table->foreignUuid('sku_id')->nullable()->constrained('case_goods_skus')->nullOnDelete();
            $table->string('varietal_claim')->nullable();       // e.g. "Syrah"
            $table->string('ava_claim')->nullable();             // e.g. "Paso Robles"
            $table->string('sub_ava_claim')->nullable();         // e.g. "Adelaida District"
            $table->integer('vintage_claim')->nullable();        // e.g. 2024
            $table->decimal('alcohol_claim', 5, 2)->nullable();  // e.g. 14.50
            $table->jsonb('other_claims')->nullable();           // future extensibility
            $table->string('compliance_status')->default('unchecked'); // passing, failing, unchecked
            $table->jsonb('compliance_snapshot')->nullable();    // full breakdown at lock time
            $table->timestamp('locked_at')->nullable();          // immutable after lock
            $table->timestamps();

            $table->index('blend_trial_id');
            $table->index('sku_id');
            $table->index('compliance_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_profiles');
    }
};
