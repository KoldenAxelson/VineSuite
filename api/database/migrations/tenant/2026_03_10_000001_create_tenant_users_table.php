<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped users table.
     * Each tenant has its own users with roles and permissions.
     * Links to central_user_id for multi-winery switching.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('central_user_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('read_only');
            $table->boolean('is_active')->default(true);
            $table->uuid('invited_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
