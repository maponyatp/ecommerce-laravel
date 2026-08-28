<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('document_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Issued invoice snapshots must be retained. Review a backup and retention plan before removal.');
    }
};
