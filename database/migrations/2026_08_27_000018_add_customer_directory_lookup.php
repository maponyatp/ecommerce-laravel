<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('directory_team_key', 32)->nullable();
            $table->string('directory_kind', 10)->nullable();
            $table->text('directory_identity')->nullable();
            $table->index(['directory_team_key', 'directory_kind'], 'customer_profiles_directory_lookup');
        });
        if (! DB::table('customer_profiles')->exists()) {
            return;
        }
        // Populate lookup metadata only for an existing exact v1 identity hash. Do not create profiles or rewrite audit history.
        DB::table('orders')->select(['id', 'team_id', 'user_id', 'customer_id', 'customer_email'])->orderBy('id')
            ->chunkById(500, function ($orders) {
                $lookup = [];
                foreach ($orders as $order) {
                    $kind = $order->user_id !== null ? 'account' : ($order->customer_id !== null ? 'legacy'
                        : ($order->customer_email !== null && trim($order->customer_email, ' ') !== '' ? 'guest' : 'unknown'));
                    $identity = match ($kind) {
                        'account' => (string) $order->user_id, 'legacy' => (string) $order->customer_id,
                        'guest' => $order->customer_email, default => (string) $order->id,
                    };
                    $team = $order->team_id === null ? null : (string) $order->team_id;
                    $key = hash('sha256', json_encode(['v1', $team, $kind, $identity], JSON_THROW_ON_ERROR));
                    $lookup[$key] = ['directory_team_key' => $team ?? 'none', 'directory_kind' => $kind, 'directory_identity' => $identity];
                }
                $keys = DB::table('customer_profiles')->whereNull('directory_kind')->whereIn('identity_key', array_keys($lookup))->pluck('identity_key');
                foreach ($keys as $key) {
                    DB::table('customer_profiles')->where('identity_key', $key)->update($lookup[$key]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropIndex('customer_profiles_directory_lookup');
            $table->dropColumn(['directory_team_key', 'directory_kind', 'directory_identity']);
        });
    }
};
