<?php

namespace App\Filament\Admin\Resources\DeliverySlots\Pages;

use App\Filament\Admin\Resources\DeliverySlots\DeliverySlotResource;
use App\Services\DeliverySlotManagementService;
use App\Support\AdminFormValidation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDeliverySlot extends CreateRecord
{
    protected static string $resource = DeliverySlotResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return AdminFormValidation::run(fn () => app(DeliverySlotManagementService::class)->save(null, $data, auth()->user()));
    }
}
