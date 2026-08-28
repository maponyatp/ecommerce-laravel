<?php

namespace App\Filament\Admin\Resources\ShippingMethods\Pages;

use App\Filament\Admin\Resources\ShippingMethods\ShippingMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShippingMethods extends ListRecords
{
    protected static string $resource = ShippingMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
