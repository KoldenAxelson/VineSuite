<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_compliance_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('label_profile_id')->constrained('label_profiles')->cascadeOnDelete();
            $table->string('rule_type');              // varietal_75, ava_85, vintage_95, conjunctive_label
            $table->decimal('threshold', 5, 2);       // e.g. 75.00, 85.00, 95.00
            $table->decimal('actual_percentage', 7, 4)->nullable(); // e.g. 78.3000
            $table->boolean('passes');
            $table->jsonb('details')->nullable();      // breakdown, remediation suggestions
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index('label_profile_id');
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_compliance_checks');
    }
};
