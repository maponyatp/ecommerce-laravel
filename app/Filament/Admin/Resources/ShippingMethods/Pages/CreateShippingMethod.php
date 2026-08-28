<?php

namespace App\Filament\Admin\Resources\ShippingMethods\Pages;

use App\Filament\Admin\Resources\ShippingMethods\ShippingMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShippingMethod extends CreateRecord
{
    protected static string $resource = ShippingMethodResource::class;
}
