<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_status_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome', 32);
            $table->string('provider_status', 64)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->timestamps();
            $table->index(['payment_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new LogicException('Payment verification history requires a reviewed backup/reconciliation plan before removal.');
    }
};
