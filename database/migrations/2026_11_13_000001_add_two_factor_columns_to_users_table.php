<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional two-step sign-in (Laravel Fortify's two-factor feature). The same
 * three columns as Fortify's own migration: all nullable, nothing else added,
 * nothing dropped, no existing row changed. Code without two-step sign-in
 * never reads them, so rolling the code back leaves sign-in working
 * (TwoStepSignInMigrationSafetyTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
