<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtc_compliance_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('state_code', 2)->unique();
            $table->string('state_name');
            $table->boolean('allows_dtc_shipping')->default(false);
            $table->unsignedInteger('annual_case_limit')->nullable();
            $table->decimal('annual_gallon_limit', 10, 1)->nullable();
            $table->boolean('license_required')->default(false);
            $table->string('license_type_required')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtc_compliance_rules');
    }
};
