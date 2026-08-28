<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('security_version')->default(0);
            $table->timestamp('staff_access_disabled_at')->nullable();
        });
        Schema::create('staff_security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('outcome', 30);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('route_name', 180)->nullable();
            $table->string('method', 10)->nullable();
            $table->json('details');
            $table->timestamp('created_at')->index();
        });
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason', 255);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->string('firewall_mode', 20)->default('monitor');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new LogicException('Security history is retained. Review a backup recovery before changing this schema.');
    }
};
