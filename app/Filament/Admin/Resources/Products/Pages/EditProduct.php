<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Product metadata saves must never overwrite inventory sold since the form opened.
        unset($data['inventory_count']);

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
