<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('draft_data')->nullable();
            $table->unsignedInteger('editor_version')->default(0);
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamp('published_at')->nullable();
        });
        // Preserve the last-known live modification date; do not republish or rewrite content.
        DB::table('pages')->where('status', 'published')->update(['published_at' => DB::raw('updated_at'), 'published_version' => 0]);
        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 30);
            $table->json('data');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('source_version')->nullable();
            $table->timestamp('created_at');
            $table->unique(['page_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new LogicException('CMS revisions are retained history. Restore a reviewed backup rather than dropping them.');
    }
};
