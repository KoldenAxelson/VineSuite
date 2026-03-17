<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ttb_report_lines', function (Blueprint $table) {
            $table->string('section', 1)->default('A')->after('part');
        });
    }

    public function down(): void
    {
        Schema::table('ttb_report_lines', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
