<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Pages\CustomerDirectory;
use App\Filament\Admin\Pages\Refunds;
use App\Filament\Admin\Pages\Returns;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Services\DeliverySchedulingService;
use App\Services\FulfillmentDocumentService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderReceiptService;
use App\Support\AdminFormValidation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return AdminFormValidation::run(fn () => app(OrderFulfillmentService::class)->update($record, $data, auth()->user()));
    }

    protected function afterSave(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData(['fulfillment_version', 'status']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refunds')->label('Refunds & credits')->icon('heroicon-o-receipt-refund')
                ->visible(fn () => Refunds::canAccess())
                ->url(fn () => Refunds::getUrl(['order' => $this->getRecord()->id])),
            Action::make('returns')->label('Returns')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn () => Returns::canAccess())
                ->url(fn () => Returns::getUrl(['order' => $this->getRecord()->id])),
            Action::make('customerHistory')->label('Customer history')->icon('heroicon-o-user-group')
                ->visible(fn () => CustomerDirectory::canAccess())
                ->url(fn () => CustomerDirectory::getUrl(['profile' => $this->getRecord()->id])),
            Action::make('packingSlip')->label('Packing slip')->icon('heroicon-o-document-text')
                ->visible(fn () => app(FulfillmentDocumentService::class)->canPrint($this->getRecord()))
                ->url(fn () => route('operations.packing-slip', $this->getRecord()))->openUrlInNewTab(),
            Action::make('resolveDelivery')->label('Resolve delivery booking')->icon('heroicon-o-calendar-days')
                ->visible(fn () => $this->getRecord()->status === 'payment_received_delivery_review')
                ->modalDescription('Agree an available window with the customer first. This changes the booking, not the payment or invoice. No customer email is sent by this action.')
                ->schema([
                    Select::make('delivery_slot_id')->label('Agreed delivery window')->required()->searchable()
                        ->options(fn () => app(DeliverySchedulingService::class)->choices($this->getRecord()->shipping_method_id)->pluck('window_label', 'id')),
                    Textarea::make('note')->label('Customer agreement / staff note')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    AdminFormValidation::run(
                        fn () => app(DeliverySchedulingService::class)->resolvePaidReview($this->getRecord(), (int) $data['delivery_slot_id'], $data['note'], auth()->user()),
                        $this->getMountedActionSchema()->getStatePath(),
                    );
                    $this->getRecord()->refresh();
                    $this->refreshFormData(['fulfillment_version', 'status']);
                    Notification::make()->title('Delivery booking confirmed')->body('The private order page is updated. Notify the customer of the agreed window.')->success()->send();
                }),
            Action::make('retryReceipt')->label('Retry failed receipt')->icon('heroicon-o-envelope')
                ->visible(fn () => $this->getRecord()->receipt?->status === 'failed')
                ->requiresConfirmation()->modalDescription('Queue another receipt attempt. This does not change payment or stock.')
                ->action(function (): void {
                    Gate::authorize('update', $this->getRecord());
                    app(OrderReceiptService::class)->retryFailed($this->getRecord());
                    $this->getRecord()->unsetRelation('receipt');
                    Notification::make()->title('Receipt queued for recovery')->success()->send();
                }),
            Action::make('staffNote')->label('Add staff note')->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->schema([Textarea::make('note')->label('Private note')->required()->maxLength(5000)])
                ->action(function (array $data): void {
                    AdminFormValidation::run(
                        fn () => app(OrderFulfillmentService::class)->addInternalNote($this->getRecord(), $data['note'], auth()->user()),
                        $this->getMountedActionSchema()->getStatePath(),
                    );
                    Notification::make()->title('Private note added')->success()->send();
                }),
            Action::make('invoice')->label('View / print invoice')->icon('heroicon-o-printer')
                ->visible(fn () => $this->getRecord()->invoice !== null)
                ->url(fn () => $this->getRecord()->invoice ? URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $this->getRecord()->invoice]) : null)
                ->openUrlInNewTab(),
        ];
    }
}
