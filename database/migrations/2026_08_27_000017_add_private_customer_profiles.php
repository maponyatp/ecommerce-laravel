<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('identity_key', 64)->unique();
            $table->string('preferred_name', 120)->nullable();
            $table->json('labels');
            $table->text('staff_notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->json('before_values');
            $table->json('after_values');
            $table->timestamps();
            $table->unique(['customer_profile_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profile_changes');
        Schema::dropIfExists('customer_profiles');
    }
};
