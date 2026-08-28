<?php

namespace App\Filament\Admin\Resources\DeliverySlots\Pages;

use App\Filament\Admin\Resources\DeliverySlots\DeliverySlotResource;
use App\Services\DeliverySlotManagementService;
use App\Support\AdminFormValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDeliverySlot extends EditRecord
{
    protected static string $resource = DeliverySlotResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AdminFormValidation::run(fn () => app(DeliverySlotManagementService::class)->save($record, $data, auth()->user()));
    }

    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData(['version']);
    }
}
