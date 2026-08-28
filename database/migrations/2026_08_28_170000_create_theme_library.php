<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_themes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->string('version', 40);
            $table->string('author', 100)->nullable();
            $table->string('source', 20);
            $table->json('settings');
            $table->json('design');
            $table->char('package_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('store_theme_state', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('active_theme_id')->nullable()->constrained('store_themes');
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('store_theme_state')->insert(['id' => 1, 'version' => 0]);
        Schema::create('store_theme_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained('store_themes');
            $table->foreignId('previous_theme_id')->nullable()->constrained('store_themes');
            $table->string('action', 30);
            $table->unsignedBigInteger('version')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Theme history must be retained. Use an explicit reviewed recovery procedure.');
    }
};
