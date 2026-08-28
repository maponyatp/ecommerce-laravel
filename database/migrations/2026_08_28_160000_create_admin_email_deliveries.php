<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 80);
            $table->string('kind', 16);
            $table->char('recipient_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('recipient_email');
            $table->string('status', 20);
            $table->string('error_class')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign', 'kind', 'recipient_hash'], 'admin_email_delivery_once');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve the delivery ledger. Review retention before removing send history.');
    }
};
