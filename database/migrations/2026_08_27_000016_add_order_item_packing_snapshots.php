<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable();
            $table->boolean('is_downloadable_snapshot')->nullable();
        });
        // Deliberately do not invent historical snapshots from today's catalogue.
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['product_name_snapshot', 'is_downloadable_snapshot']));
    }
};
