<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-scoped team invitations table.
     * Stores pending and accepted invitations for team members.
     */
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->uuid('invited_by');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('invited_by')->references('id')->on('users')->cascadeOnDelete();

            // Prevent duplicate pending invitations to the same email
            // (unique constraint on email where accepted_at is null handled in application logic)
            $table->index('email');
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
