<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product menus for the fictional demo merchants (ADR 0023). Runs on the
 * live RDS database, so it only adds two new tables: no existing table,
 * value or ledger entry changes. Display only: nothing here is sent to
 * Redemption Fraud Detection.
 *
 * - merchant_products: a demo merchant's menu (never a real candidate's).
 * - redemption_items: what a redemption was for, with the product's name
 *   and price copied at the time, so editing a menu never rewrites history.
 *   An "Other amount" part is a row with no product. For an itemized
 *   redemption the rows add up to its amount_cents exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('price_cents');
            $table->string('description', 160)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('redemption_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedSmallInteger('quantity');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemption_items');
        Schema::dropIfExists('merchant_products');
    }
};
