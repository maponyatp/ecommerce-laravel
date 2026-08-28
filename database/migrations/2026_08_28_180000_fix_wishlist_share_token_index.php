<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One share link represents many product rows belonging to one customer.
        // Preserve existing links and the unique (user_id, product_id) constraint.
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropUnique('wishlists_share_token_unique');
            $table->index('share_token');
        });
    }

    public function down(): void
    {
        if (DB::table('wishlists')->whereNotNull('share_token')->groupBy('share_token')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the unique wishlist token index while multi-product shared lists exist. Existing shares must not be discarded.');
        }
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropIndex('wishlists_share_token_index');
            $table->unique('share_token');
        });
    }
};
