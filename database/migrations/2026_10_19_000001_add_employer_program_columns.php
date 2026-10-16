<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employer program and the merchant login (ADR 0014). Runs on the live
 * RDS database, so it only adds columns that are empty or have a default:
 * no existing value changes and no ledger entry is touched.
 *
 * - employers: a monthly budget and three optional occasions (empty = off).
 * - credit_purchases: a status. Every purchase so far was recorded and paid
 *   (simulated) at once, which is the default; purchases from the employer
 *   dashboard start pending and only reach the ledger once paid.
 * - credit_allocations: which occasion an allocation was for, if any.
 * - merchants: the login that runs the business, like employees.user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employers', function (Blueprint $table) {
            $table->unsignedInteger('monthly_budget_cents')->nullable();
            $table->unsignedInteger('welcome_cents')->nullable();
            $table->unsignedInteger('birthday_cents')->nullable();
            $table->unsignedInteger('recognition_cents')->nullable();
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->string('status', 20)->default('simulated');
        });

        Schema::table('credit_allocations', function (Blueprint $table) {
            $table->string('occasion', 20)->nullable();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('credit_allocations', fn (Blueprint $table) => $table->dropColumn('occasion'));
        Schema::table('credit_purchases', fn (Blueprint $table) => $table->dropColumn('status'));

        Schema::table('employers', function (Blueprint $table) {
            $table->dropColumn(['monthly_budget_cents', 'welcome_cents', 'birthday_cents', 'recognition_cents']);
        });
    }
};
