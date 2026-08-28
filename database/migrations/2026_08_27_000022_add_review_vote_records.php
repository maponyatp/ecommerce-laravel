<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedInteger('helpful_votes')->default(0);
            $table->unsignedInteger('unhelpful_votes')->default(0);
        });
        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vote', 16);
            $table->timestamps();
            $table->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Review vote history requires a reviewed backup/reconciliation plan before rollback.');
    }
};
