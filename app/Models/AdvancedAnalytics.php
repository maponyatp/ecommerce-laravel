<?php

namespace App\Models;

use App\Support\StoreMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvancedAnalytics
{
    public static function getCubes(): array
    {
        return [
            'sales' => 'Sales and engagement',
            'customers' => 'Legacy customer segments',
        ];
    }

    public static function getDimensions(string $cube): array
    {
        if ($cube === 'sales') {
            return [
                'product_id' => 'Product',
                'category_id' => 'Category',
                'date' => 'Date (Daily)',
            ];
        }

        if ($cube === 'customers') {
            return [
                'customer_segment' => 'Customer Segment',
            ];
        }

        return [];
    }

    public static function getMeasures(string $cube): array
    {
        if ($cube === 'sales') {
            return [
                'revenue' => 'Paid item sales ('.StoreMoney::currency().', before order discounts)',
                'views' => 'Total Views',
                'purchases' => 'Total Purchases',
                'add_to_cart' => 'Total Add to Carts',
                'conversion_rate' => 'Average Conversion Rate (%)',
            ];
        }

        if ($cube === 'customers') {
            return [
                'total_orders' => 'Total Orders Placed',
            ];
        }

        return [];
    }

    public static function queryCube(string $cube, string $dimension, string $measure, ?string $startDate = null, ?string $endDate = null): array
    {
        if (! array_key_exists($cube, static::getCubes())
            || ! array_key_exists($dimension, static::getDimensions($cube))
            || ! array_key_exists($measure, static::getMeasures($cube))) {
            throw ValidationException::withMessages(['selectedMeasure' => 'Choose a supported report, grouping and measure.']);
        }
        $startDate = $startDate ?? now()->subDays(30)->toDateString();
        $endDate = $endDate ?? now()->toDateString();

        if ($cube === 'sales' && $measure === 'revenue') {
            $query = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
                ->where('orders.payment_status', 'paid')->where('orders.currency', StoreMoney::currency())
                ->whereDate('orders.order_date', '>=', $startDate)->whereDate('orders.order_date', '<=', $endDate);
            if ($dimension === 'product_id') {
                $query->selectRaw("COALESCE(products.name, 'Unavailable product') as label")->groupBy('order_items.product_id', 'products.name');
            } elseif ($dimension === 'category_id') {
                $query->selectRaw("COALESCE(product_categories.name, 'Uncategorized') as label")->groupBy('product_categories.id', 'product_categories.name');
            } else {
                $query->selectRaw('DATE(orders.order_date) as label')->groupByRaw('DATE(orders.order_date)');
            }

            return $query->selectRaw('SUM(order_items.price * order_items.quantity) as value')->orderByDesc('value')->limit(200)->get()->all();
        }

        if ($cube === 'sales') {
            $query = DB::table('product_performance')
                ->join('products', 'product_performance.product_id', '=', 'products.id')
                ->leftJoin('product_categories', 'products.category_id', '=', 'product_categories.id');

            if ($dimension === 'product_id') {
                $query->select('products.name as label');
                $query->groupBy('products.name');
            } elseif ($dimension === 'category_id') {
                $query->select(DB::raw('COALESCE(product_categories.name, "Uncategorized") as label'));
                $query->groupBy('product_categories.name');
            } else {
                $query->select('product_performance.date as label');
                $query->groupBy('product_performance.date');
            }

            $query->whereBetween('product_performance.date', [$startDate, $endDate]);

            if ($measure === 'conversion_rate') {
                $query->addSelect(DB::raw('ROUND(AVG(product_performance.conversion_rate), 2) as value'));
            } elseif ($measure === 'revenue') {
                $query->addSelect(DB::raw('SUM(product_performance.revenue) as value'));
            } else {
                $query->addSelect(DB::raw("SUM(product_performance.{$measure}) as value"));
            }

            return $query->get()->toArray();
        }

        if ($cube === 'customers') {
            $query = DB::table('customer_metrics');

            if ($dimension === 'customer_segment') {
                $query->select('customer_segment as label');
                $query->groupBy('customer_segment');
            } else {
                $query->select('customer_segment as label');
                $query->groupBy('customer_segment');
            }

            if ($measure === 'lifetime_value') {
                $query->addSelect(DB::raw('SUM(lifetime_value) as value'));
            } elseif ($measure === 'average_order_value') {
                $query->addSelect(DB::raw('ROUND(AVG(average_order_value), 2) as value'));
            } else {
                $query->addSelect(DB::raw('SUM(total_orders) as value'));
            }

            return $query->get()->toArray();
        }

        return [];
    }
}
