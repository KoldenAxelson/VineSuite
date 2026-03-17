<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winery_profiles', function (Blueprint $table) {
            $table->jsonb('certification_types')->nullable()->after('onboarding_complete');
        });
    }

    public function down(): void
    {
        Schema::table('winery_profiles', function (Blueprint $table) {
            $table->dropColumn('certification_types');
        });
    }
};
