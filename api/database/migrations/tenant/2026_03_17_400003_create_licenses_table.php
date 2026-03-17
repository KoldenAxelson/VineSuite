<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_type'); // ttb_permit, state_license, cola
            $table->string('jurisdiction'); // federal, or state name (e.g., "California")
            $table->string('license_number');
            $table->date('issued_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->unsignedSmallInteger('renewal_lead_days')->default(90);
            $table->string('document_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('license_type');
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
