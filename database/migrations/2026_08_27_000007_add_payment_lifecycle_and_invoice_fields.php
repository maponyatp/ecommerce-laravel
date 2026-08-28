<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->decimal('total_amount', 12, 2)->change();
            $table->timestamp('payment_processed_at')->nullable();
            $table->timestamp('inventory_committed_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('invoice_number')->nullable()->unique();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('external_transaction_id')->unique();
            $table->string('gateway_reference')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 40)->default('pending')->index();
            $table->string('response_code', 20)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn('invoice_number');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_processed_at',
                'inventory_committed_at',
                'confirmation_sent_at',
            ]);
        });
    }
};
