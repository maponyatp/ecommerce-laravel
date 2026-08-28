<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('fulfillment_version')->default(0);
            $table->string('delivery_carrier', 120)->nullable();
            $table->string('tracking_url', 2048)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
        });
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->json('postal_codes')->nullable());
    }

    public function down(): void
    {
        Schema::table('shipping_methods', fn (Blueprint $table) => $table->dropColumn('postal_codes'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'fulfillment_version', 'delivery_carrier', 'tracking_url', 'shipped_at', 'delivered_at',
        ]));
    }
};
