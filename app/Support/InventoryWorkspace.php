<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryWorkspace
{
    /** One row per actual stock unit, never the unused parent of a variable product. */
    public function query(string $search = '', string $status = 'all', ?int $productId = null): Builder
    {
        $simple = DB::table('products')->where('has_variants', false)->selectRaw('id as product_id, NULL as variant_id, name, NULL as sku, NULL as option_title, inventory_count as on_hand, COALESCE(low_stock_threshold, 5) as threshold, 1 as active, is_downloadable');
        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.has_variants', true)->whereNotNull('v.draft_id')
            ->selectRaw('p.id as product_id, v.id as variant_id, p.name, v.sku, v.title as option_title, v.inventory_quantity as on_hand, COALESCE(p.low_stock_threshold, 5) as threshold, v.active, p.is_downloadable');
        $held = DB::table('stock_reservations')->selectRaw('product_id, variant_key, SUM(quantity) as reserved')
            ->whereNull('released_at')->whereNull('committed_at')->where('expires_at', '>', now())->groupBy('product_id', 'variant_key');
        $units = DB::query()->fromSub($simple->unionAll($variants), 'units')->leftJoinSub($held, 'holds', function ($join) {
            $join->on('holds.product_id', '=', 'units.product_id')->whereRaw('holds.variant_key = COALESCE(units.variant_id, 0)');
        })->selectRaw('units.*, COALESCE(holds.reserved, 0) as reserved, CASE WHEN units.on_hand > COALESCE(holds.reserved, 0) THEN units.on_hand - COALESCE(holds.reserved, 0) ELSE 0 END as available');
        $query = DB::query()->fromSub($units, 'stock');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("option_title LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }

        return match ($status) {
            'low' => $query->where('active', true)->whereColumn('available', '<=', 'threshold'),
            'out' => $query->where('active', true)->where('available', 0),
            'paused' => $query->where('active', false),
            default => $query,
        };
    }

    public function export(Builder $query): StreamedResponse
    {
        // Bound the snapshot; never leave a predictable report in public storage.
        $rows = $query->orderBy('product_id')->orderBy('variant_id')->limit(10001)->get();
        abort_if($rows->count() > 10000, 422, 'Narrow the inventory search to 10,000 stock units or fewer.');

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Product', 'Option', 'SKU', 'On hand', 'Reserved', 'Available after holds', 'Low-stock threshold', 'Sale status', 'Stock type'], ',', '"', '');
            foreach ($rows as $row) {
                $text = fn ($value) => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', (string) $value) ? "'".$value : $value;
                fputcsv($stream, [$text($row->name), $text($row->option_title), $text($row->sku), $row->on_hand, $row->reserved,
                    $row->available, $row->threshold, $row->active ? 'Enabled' : 'Paused', $row->is_downloadable ? 'Digital allocation' : 'Physical'], ',', '"', '');
            }
            fclose($stream);
        }, 'inventory-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
