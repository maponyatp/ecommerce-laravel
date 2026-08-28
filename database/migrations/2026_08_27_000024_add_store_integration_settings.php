<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_integrations', function (Blueprint $table) {
            $table->string('provider', 32)->primary();
            $table->longText('credentials');
            $table->json('configuration');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('store_integration_changes', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('changed_fields');
            $table->unsignedInteger('version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Encrypted integration settings require a reviewed backup before removal.');
    }
};
