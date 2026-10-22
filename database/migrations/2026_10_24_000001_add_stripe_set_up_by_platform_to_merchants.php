<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the Stripe test accounts Local Goods set up itself for fictional
 * demo merchants with no login ("Connect demo merchants to Stripe"), so
 * their status says so honestly. Runs on the live RDS database, so it only
 * adds a column: every existing merchant starts false (onboarded itself,
 * or not connected), and nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('stripe_set_up_by_platform')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('stripe_set_up_by_platform');
        });
    }
};
