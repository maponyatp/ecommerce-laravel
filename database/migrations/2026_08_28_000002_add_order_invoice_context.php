<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('billing_details')->nullable();
            $table->json('invoice_context')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain historical billing and tax context; restore a reviewed backup instead.');
    }
};
