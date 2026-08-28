<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->boolean('has_variants')->default(false));
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('draft_id')->nullable()->unique()->constrained('product_variant_drafts')->restrictOnDelete();
            $table->boolean('active')->default(false);
            $table->string('currency', 3)->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sku_snapshot')->nullable();
            $table->json('options_snapshot')->nullable();
        });
        Schema::table('stock_reservations', function (Blueprint $table) {
            // Zero identifies the simple product, avoiding SQL NULL uniqueness differences.
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->unique(['order_id', 'product_id', 'variant_key'], 'stock_hold_line_unique');
            $table->dropUnique(['order_id', 'product_id']);
        });
        Schema::table('inventory_logs', fn (Blueprint $table) => $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('product_variant_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('draft_version');
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Purchases may reference these identities. Restore from a verified backup instead.
        throw new LogicException('Purchasable variants cannot be rolled back after publication.');
    }
};
