<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 3)->nullable();
            $table->string('delivery_contact_name')->nullable();
            $table->string('delivery_phone', 32)->nullable();
            $table->string('shipping_country', 2)->nullable();
            $table->string('shipping_city', 120)->nullable();
            $table->string('shipping_region', 120)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
        });

        // Only infer historical currency from recorded gateway transactions.
        // Ambiguous legacy orders remain explicitly unknown, never relabelled as ZAR.
        DB::table('payment_transactions')->select('order_id')->distinct()->orderBy('order_id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $currencies = DB::table('payment_transactions')->where('order_id', $row->order_id)
                        ->whereNotNull('currency')->distinct()->pluck('currency');
                    if ($currencies->count() === 1 && preg_match('/^[A-Z]{3}$/', $currencies->first())) {
                        DB::table('orders')->where('id', $row->order_id)->update(['currency' => $currencies->first()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn([
            'currency', 'delivery_contact_name', 'delivery_phone', 'shipping_country',
            'shipping_city', 'shipping_region', 'shipping_postal_code',
        ]));
    }
};
