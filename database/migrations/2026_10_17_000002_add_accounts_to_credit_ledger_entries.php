<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger now has three kinds of account: employee (as before), employer
 * and merchant. Each entry belongs to exactly one (checked in
 * CreditLedgerEntry). Runs on the live RDS database, so it only adds
 * nullable columns and lets employee_id be empty: no existing value is
 * changed and no row is removed. LedgerMigrationSafetyTest proves every
 * employee's balance is identical before and after.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        // MySQL won't always change a column a foreign key points through, so
        // take the key off, change the column, and put the same key back.
        if ($mysql) {
            Schema::table('credit_ledger_entries', fn (Blueprint $table) => $table->dropForeign(['employee_id']));
        }

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });

        if ($mysql) {
            Schema::table('credit_ledger_entries', fn (Blueprint $table) => $table->foreign('employee_id')->references('id')->on('employees'));
        }

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->foreignId('employer_id')->nullable()->constrained();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->foreignId('credit_purchase_id')->nullable()->constrained();
            $table->foreignId('credit_allocation_id')->nullable()->constrained();
            $table->foreignId('merchant_payout_id')->nullable()->constrained();
            $table->index(['employer_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
        });
    }

    /**
     * Going back means employee_id can't be empty again, so employer and
     * merchant entries would have to go. In production that is refused;
     * locally and in tests they are demo data that Reset demo rebuilds.
     */
    public function down(): void
    {
        $others = DB::table('credit_ledger_entries')->whereNull('employee_id');

        if ($others->exists()) {
            if (app()->isProduction()) {
                throw new RuntimeException('Refusing to roll back: the ledger has employer or merchant entries that would be lost.');
            }

            $others->delete();
        }

        $columns = ['employer_id', 'merchant_id', 'credit_purchase_id', 'credit_allocation_id', 'merchant_payout_id'];

        // Keys first: MySQL may be using the indexes below for them.
        Schema::table('credit_ledger_entries', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                $table->dropForeign([$column]);
            }
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) use ($columns) {
            $table->dropIndex(['employer_id', 'created_at']);
            $table->dropIndex(['merchant_id', 'created_at']);
            $table->dropColumn($columns);
        });

        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            Schema::table('credit_ledger_entries', fn (Blueprint $table) => $table->dropForeign(['employee_id']));
        }

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });

        if ($mysql) {
            Schema::table('credit_ledger_entries', fn (Blueprint $table) => $table->foreign('employee_id')->references('id')->on('employees'));
        }
    }
};
