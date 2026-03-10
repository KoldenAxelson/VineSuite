<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped winery profile — one per tenant.
     *
     * Stores winery identity, location, TTB compliance info, and preferences
     * that affect calculations and reporting across all modules.
     */
    public function up(): void
    {
        Schema::create('winery_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identity
            $table->string('name');
            $table->string('dba_name')->nullable();  // "doing business as"
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Location
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('country', 2)->default('US');
            $table->string('timezone')->default('America/Los_Angeles');

            // Compliance / TTB
            $table->string('ttb_permit_number')->nullable();
            $table->string('ttb_registry_number')->nullable();
            $table->string('state_license_number')->nullable();

            // Preferences
            $table->string('unit_system')->default('imperial'); // 'imperial' (gallons) or 'metric' (liters)
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1); // 1=January
            $table->string('date_format')->default('m/d/Y');

            // Subscription / plan info (duplicated from tenant for easy access)
            $table->boolean('onboarding_complete')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winery_profiles');
    }
};
