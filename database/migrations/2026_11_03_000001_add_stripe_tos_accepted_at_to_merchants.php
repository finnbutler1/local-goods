<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Local Goods accepted Stripe's terms for a demo merchant it set up
 * ("Connect demo merchants to Stripe"), fixed together with
 * stripe_onboarding_key, so a retry sends Stripe identical parameters and
 * gets the same account back instead of a refusal. Runs on the live RDS
 * database, so it only adds a nullable column; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->timestamp('stripe_tos_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('stripe_tos_accepted_at');
        });
    }
};
