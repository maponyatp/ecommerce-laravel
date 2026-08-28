<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->boolean('requires_delivery_slot')->default(false);
        });
        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->dateTime('booking_closes_at');
            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('delivery_scheduled_at')->nullable()->index();
            $table->dateTime('delivery_window_end')->nullable();
        });
        Schema::create('delivery_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_slot_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['delivery_slot_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_bookings');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_slot_id');
            $table->dropColumn(['delivery_scheduled_at', 'delivery_window_end']);
        });
        Schema::dropIfExists('delivery_slots');
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->dropColumn('requires_delivery_slot'));
    }
};
