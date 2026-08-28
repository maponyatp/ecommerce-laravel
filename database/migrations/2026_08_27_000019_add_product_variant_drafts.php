<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Planning data is separate from sellable variants and inventory.
        Schema::create('product_variant_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('title', 120);
            $table->json('options');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3);
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['product_id', 'archived']);
        });
        Schema::create('product_variant_draft_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_draft_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('before_values')->nullable();
            $table->json('after_values');
            $table->timestamps();
            $table->unique(['product_variant_draft_id', 'version'], 'variant_draft_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_draft_changes');
        Schema::dropIfExists('product_variant_drafts');
    }
};
