<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['recipient_name', 'recipient_email', 'gift_message'] as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $column === 'gift_message' ? $table->text($column)->nullable() : $table->string($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['recipient_name', 'recipient_email', 'gift_message']));
    }
};
