<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmation_basis', 50)->nullable();
            $table->string('provider_reference_hash', 64)->nullable()->unique();
            $table->timestamp('external_completed_at')->nullable();
        });
        Schema::create('refund_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->json('data');
            $table->timestamp('created_at');
            $table->unique(['refund_id', 'version']);
        });
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('number')->unique();
            $table->json('document_snapshot');
            $table->timestamp('issued_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Refund and credit records must be retained. Review a backup recovery instead of dropping financial history.');
    }
};
