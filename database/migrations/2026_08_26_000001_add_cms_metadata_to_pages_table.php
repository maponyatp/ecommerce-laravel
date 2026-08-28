<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('meta_title')->nullable()->after('content');
            $table->string('meta_description', 500)->nullable()->after('meta_title');
            $table->string('featured_image')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'featured_image']);
        });
    }
};
