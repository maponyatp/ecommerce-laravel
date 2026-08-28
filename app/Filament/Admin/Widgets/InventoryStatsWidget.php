<?php

namespace App\Filament\Admin\Widgets;

use App\Services\AnalyticsService;
use App\Support\StoreMoney;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStatsWidget extends BaseWidget
{
    protected static ?int $sort = 7;

    protected function getStats(): array
    {
        $analyticsService = app(AnalyticsService::class);
        $insights = $analyticsService->getInventoryInsights();

        return [
            Stat::make('Inventory retail value', StoreMoney::format($insights['inventory_value']))
                ->description('At catalogue prices, not cost of goods')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('success'),

            Stat::make('Low Stock Items', number_format($insights['low_stock_count']))
                ->description('Products below threshold')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('warning'),

            Stat::make('Out of Stock', number_format($insights['out_of_stock_count']))
                ->description('Products unavailable')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),
        ];
    }
}
