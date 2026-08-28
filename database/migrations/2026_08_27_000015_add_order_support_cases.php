<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('active_order_id')->nullable()->unique();
            $table->uuid('submission_key');
            $table->string('category', 40);
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->unsignedInteger('version')->default(0);
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'submission_key']);
        });
        Schema::create('order_issue_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20);
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->uuid('submission_key')->nullable();
            $table->timestamps();
            $table->unique(['order_issue_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_issue_messages');
        Schema::dropIfExists('order_issues');
    }
};
