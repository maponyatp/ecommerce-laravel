<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_fingerprint', 64)->nullable();
            $table->text('resolution')->nullable();
        });
        Schema::table('return_request_items', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable();
            $table->unsignedInteger('received_quantity')->default(0);
            $table->string('disposition')->nullable();
        });
        Schema::create('return_request_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->text('note');
            $table->timestamps();
            $table->unique(['return_request_id', 'version'], 'return_revision_unique');
        });
    }

    public function down(): void
    {
        // Keep operational history and guest returns; destructive rollback needs explicit review.
        throw new RuntimeException('Restore a reviewed backup to roll back return operations; do not discard return history automatically.');
    }
};
