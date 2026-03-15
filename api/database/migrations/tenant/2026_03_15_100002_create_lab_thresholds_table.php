<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('test_type', 50);
            $table->string('variety', 100)->nullable();
            $table->decimal('min_value', 12, 6)->nullable();
            $table->decimal('max_value', 12, 6)->nullable();
            $table->string('alert_level', 20);
            $table->timestamps();

            // A threshold is unique per (test_type, variety, alert_level)
            // variety=null means "applies to all varieties"
            $table->unique(['test_type', 'variety', 'alert_level'], 'lab_thresholds_type_variety_level_unique');
            $table->index('test_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_thresholds');
    }
};
