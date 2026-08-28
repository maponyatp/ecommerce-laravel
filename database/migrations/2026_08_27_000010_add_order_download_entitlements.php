<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Retain ownership even after account deletion; never reassign by email.
            $table->unsignedBigInteger('user_id')->nullable()->index();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('download_path')->nullable();
            $table->unsignedInteger('download_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['download_path', 'download_limit']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('user_id'));
    }
};
