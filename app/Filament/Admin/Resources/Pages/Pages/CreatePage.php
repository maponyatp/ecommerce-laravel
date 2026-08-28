<?php

namespace App\Filament\Admin\Resources\Pages\Pages;

use App\Filament\Admin\Resources\Pages\PageResource;
use App\Services\PagePublishingService;
use App\Support\AdminFormValidation;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return AdminFormValidation::run(fn () => app(PagePublishingService::class)->saveDraft(null, $data, auth()->user()));
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Create draft');
    }

    protected function getRedirectUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
