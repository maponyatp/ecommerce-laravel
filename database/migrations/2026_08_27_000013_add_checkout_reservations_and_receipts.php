<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_key', 64)->nullable()->unique();
            $table->timestamp('stock_reserved_until')->nullable()->index();
            $table->string('stock_reservation_status', 20)->nullable();
        });
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
            $table->index(['product_id', 'released_at', 'committed_at', 'expires_at'], 'stock_holds_available');
        });
        Schema::create('order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_token', 36)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipts');
        Schema::dropIfExists('stock_reservations');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['checkout_key', 'stock_reserved_until', 'stock_reservation_status']));
    }
};
