<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getSubheading(): ?string
    {
        return 'Choose a work queue, then search by order number or customer email. Manage opens the order, invoice and delivery controls.';
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All orders')];
        foreach (Order::WORK_QUEUES as $key => $label) {
            $tabs[$key] = Tab::make($label)
                ->badge(fn () => OrderResource::getEloquentQuery()->workQueue($key)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->workQueue($key));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('deliveryList')->label('Daily delivery list')
            ->url(route('operations.delivery-list'))->openUrlInNewTab()];
    }
}
