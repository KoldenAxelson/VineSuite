<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('hours', 8, 2)->default(0)->after('completion_notes');
            $table->decimal('labor_cost', 12, 4)->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['hours', 'labor_cost']);
        });
    }
};
