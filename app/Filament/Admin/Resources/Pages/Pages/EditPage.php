<?php

namespace App\Filament\Admin\Resources\Pages\Pages;

use App\Filament\Admin\Resources\Pages\PageResource;
use App\Services\PagePublishingService;
use App\Support\AdminFormValidation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_replace($data, app(PagePublishingService::class)->draft($this->getRecord()), ['editor_version' => $this->getRecord()->editor_version]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AdminFormValidation::run(fn () => app(PagePublishingService::class)->saveDraft($record, $data, auth()->user()));
    }

    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved — live page unchanged';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Preview saved draft')->icon('heroicon-o-eye')->color('gray')
                ->url(fn () => URL::temporarySignedRoute('cms.pages.preview', now()->addMinutes(30), ['page' => $this->getRecord()->id, 'version' => $this->getRecord()->editor_version]))->openUrlInNewTab(),
            Action::make('live')->label('View live page')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->visible(fn () => $this->getRecord()->isPublished())->url(fn () => $this->getRecord()->publicUrl())->openUrlInNewTab(),
            $this->publishingAction('publish', 'Publish saved draft', 'Publish the last saved draft to the storefront. Unsaved form edits are not included. The page URL and existing menu links stay unchanged.'),
            $this->publishingAction('unpublish', 'Unpublish page', 'Hide this CMS page without deleting its history. Remove unwanted menu links separately. About, contact and shop URLs revert to their built-in pages.')
                ->color('gray')->visible(fn () => $this->getRecord()->isPublished() && Gate::allows('publish', $this->getRecord())),
            Action::make('restore')->label('Restore revision as draft')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn () => Gate::allows('update', $this->getRecord()))
                ->modalDescription('Copy a revision into a new draft. This never changes the live page until you publish it. Unsaved edits will be replaced.')
                ->fillForm(fn () => ['editor_version' => $this->data['editor_version']])
                ->schema([TextInput::make('editor_version')->label('Current saved revision')->disabled()->dehydrated()->required()->integer(),
                    TextInput::make('revision')->label('Revision number from the history')->required()->integer()->minValue(0)])
                ->action(function (array $data): void {
                    AdminFormValidation::run(fn () => app(PagePublishingService::class)->restore($this->getRecord(), (int) $data['revision'], (int) $data['editor_version'], auth()->user()), $this->getMountedActionSchema()->getStatePath());
                    $this->afterSave();
                    Notification::make()->title('Revision restored as a draft')->body('Preview it before publishing. The live page is unchanged.')->success()->send();
                }),
        ];
    }

    private function publishingAction(string $name, string $label, string $description): Action
    {
        return Action::make($name)->label($label)->visible(fn () => Gate::allows('publish', $this->getRecord()))
            ->modalDescription($description)->modalSubmitActionLabel($label)
            ->fillForm(fn () => ['editor_version' => $this->data['editor_version']])
            ->schema([TextInput::make('editor_version')->label('Saved revision')->disabled()->dehydrated()->required()->integer()])
            ->action(function (array $data) use ($name): void {
                AdminFormValidation::run(fn () => app(PagePublishingService::class)->{$name}($this->getRecord(), (int) $data['editor_version'], auth()->user()), $this->getMountedActionSchema()->getStatePath());
                $this->afterSave();
                Notification::make()->title($name === 'publish' ? 'Saved draft published' : 'CMS page unpublished')->success()->send();
            });
    }
}
