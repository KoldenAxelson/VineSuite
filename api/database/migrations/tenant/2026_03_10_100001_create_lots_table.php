<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('variety');
            $table->unsignedSmallInteger('vintage');
            $table->string('source_type'); // estate, purchased
            $table->jsonb('source_details')->nullable(); // vineyard, block, grower
            $table->decimal('volume_gallons', 12, 4)->default(0);
            $table->string('status')->default('in_progress'); // in_progress, aging, finished, bottled, sold, archived
            $table->uuid('parent_lot_id')->nullable();
            $table->timestamps();

            $table->index('variety');
            $table->index('vintage');
            $table->index('status');
        });

        // Self-referencing FK must be added after the table (and its PK) exist.
        // PostgreSQL requires the referenced column's unique constraint to be
        // committed before a FK can reference it.
        Schema::table('lots', function (Blueprint $table) {
            $table->foreign('parent_lot_id')->references('id')->on('lots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropForeign(['parent_lot_id']);
        });
        Schema::dropIfExists('lots');
    }
};
