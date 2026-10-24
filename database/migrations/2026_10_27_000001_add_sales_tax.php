<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DC sales tax shown at redemption (ADR 0027). Runs on the live RDS
 * database, so it only adds columns: no existing amount or ledger entry
 * changes, and no money row is created.
 *
 * - merchant_products.tax_category: prepared_food, general or exempt. Every
 *   existing product is backfilled from its merchant's category, using the
 *   defaults below (a copy of config/tax.php's, so a later config edit
 *   never changes what this migration did).
 * - redemption_items.tax_category / tax_rate_bps: the category and rate
 *   snapshotted on each item line.
 * - redemptions and pay_codes: tax_basis ("added" for menu items,
 *   "included" for a typed-in amount, null for rows from before tax
 *   display), subtotal_cents, tax_cents and tax_lines (one per category).
 *   amount_cents stays the total, as before.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'prepared_food' => ['cafe', 'restaurant'],
        'general' => ['retail', 'fitness'],
        'exempt' => ['salon'],
    ];

    public function up(): void
    {
        Schema::table('merchant_products', function (Blueprint $table) {
            $table->string('tax_category', 24)->nullable();
        });

        Schema::table('redemption_items', function (Blueprint $table) {
            $table->string('tax_category', 24)->nullable();
            $table->unsignedSmallInteger('tax_rate_bps')->nullable();
        });

        foreach (['redemptions', 'pay_codes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('tax_basis', 16)->nullable();
                $table->unsignedInteger('subtotal_cents')->nullable();
                $table->unsignedInteger('tax_cents')->nullable();
                $table->json('tax_lines')->nullable();
            });
        }

        foreach (self::DEFAULTS as $category => $merchantCategories) {
            DB::table('merchant_products')
                ->whereIn('merchant_id', DB::table('merchants')->select('id')->whereIn('category', $merchantCategories))
                ->update(['tax_category' => $category]);
        }

        DB::table('merchant_products')->whereNull('tax_category')->update(['tax_category' => 'general']);
    }

    public function down(): void
    {
        foreach (['redemptions', 'pay_codes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['tax_basis', 'subtotal_cents', 'tax_cents', 'tax_lines']);
            });
        }

        Schema::table('redemption_items', function (Blueprint $table) {
            $table->dropColumn(['tax_category', 'tax_rate_bps']);
        });

        Schema::table('merchant_products', function (Blueprint $table) {
            $table->dropColumn('tax_category');
        });
    }
};
