<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central Cashier tables for Stripe subscription billing.
     *
     * Billing is per-tenant (not per-user). The Tenant model is the Billable.
     * These tables live in the central (public) schema alongside tenants/domains.
     */
    public function up(): void
    {
        // Add Cashier columns to tenants table
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->index();
            }
            $table->string('pm_type')->nullable()->after('stripe_subscription_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            $table->timestamp('trial_ends_at')->nullable()->after('pm_last_four');
        });

        // Cashier subscriptions table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stripe_status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Cashier subscription items table
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
