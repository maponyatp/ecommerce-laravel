<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            if (! Schema::hasColumn('ratings', 'overall_rating')) {
                $table->unsignedTinyInteger('overall_rating')->nullable();
            }
            if (! Schema::hasColumn('ratings', 'quality_rating')) {
                $table->unsignedTinyInteger('quality_rating')->nullable();
            }
            if (! Schema::hasColumn('ratings', 'value_rating')) {
                $table->unsignedTinyInteger('value_rating')->nullable();
            }
            if (! Schema::hasColumn('ratings', 'price_rating')) {
                $table->unsignedTinyInteger('price_rating')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropColumn(['overall_rating', 'quality_rating', 'value_rating', 'price_rating']);
        });
    }
};
