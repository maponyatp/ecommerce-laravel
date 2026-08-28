<?php

namespace App\Filament\Admin\Resources\OrderIssues\Pages;

use App\Filament\Admin\Resources\OrderIssues\OrderIssueResource;
use App\Services\OrderSupportService;
use App\Support\AdminFormValidation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOrderIssue extends EditRecord
{
    protected static string $resource = OrderIssueResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AdminFormValidation::run(fn () => app(OrderSupportService::class)->update($record, $data, auth()->user()));
    }

    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData(['version', 'status']);
        $this->data['public_message'] = null;
        $this->data['internal_note'] = null;
    }
}
