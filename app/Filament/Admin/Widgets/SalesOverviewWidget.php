<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $metrics = $analyticsService->getSalesMetrics();

        return [
            Stat::make('Paid order totals — '.StoreMoney::currency(), StoreMoney::format($metrics['total_revenue']))
                ->description($metrics['revenue_growth'] >= 0
                    ? $metrics['revenue_growth'].'% increase'
                    : abs($metrics['revenue_growth']).'% decrease')
                ->descriptionIcon($metrics['revenue_growth'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($metrics['revenue_growth'] >= 0 ? 'success' : 'danger'),

            Stat::make('Paid '.StoreMoney::currency().' orders', number_format($metrics['order_count']))
                ->description($metrics['order_growth'] >= 0
                    ? $metrics['order_growth'].'% increase'
                    : abs($metrics['order_growth']).'% decrease')
                ->descriptionIcon($metrics['order_growth'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($metrics['order_growth'] >= 0 ? 'success' : 'danger'),

            Stat::make('Average paid order', StoreMoney::format($metrics['avg_order_value']))
                ->description('Last 30 days; '.$metrics['excluded_currency_orders'].' other/unknown-currency orders excluded')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('primary'),
        ];
    }
}
