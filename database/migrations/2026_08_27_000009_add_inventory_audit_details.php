<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['old_quantity', 'new_quantity', 'reference_id', 'reference_type'] as $column) {
            if (! Schema::hasColumn('inventory_logs', $column)) {
                Schema::table('inventory_logs', function (Blueprint $table) use ($column) {
                    match ($column) {
                        'reference_type' => $table->string($column)->nullable(),
                        'reference_id' => $table->unsignedBigInteger($column)->nullable(),
                        default => $table->integer($column)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('inventory_logs', fn (Blueprint $table) => $table->dropColumn(['old_quantity', 'new_quantity', 'reference_id', 'reference_type']));
    }
};
