<?php

namespace App\Filament\Admin\Resources\PaymentTransactions;

use App\Filament\Admin\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payments\IkhokhaGateway;
use App\Services\Payments\IkhokhaReconciliationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin'])
            && Gate::allows('viewAny', Order::class) && Gate::allows('view', new Order);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order.id')->label('Order')->formatStateUsing(fn ($state) => '#'.$state)->searchable(),
            TextColumn::make('gateway')->badge(),
            TextColumn::make('gateway_reference')->label('Gateway reference')->searchable()->copyable(),
            TextColumn::make('amount')->formatStateUsing(fn ($state, PaymentTransaction $record) => $record->currency.' '.number_format((float) $state, 2))->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('response_code')->label('Code'),
            TextColumn::make('paid_at')->dateTime()->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('latestStatusCheck.outcome')->label('Last verification')->badge()->placeholder('Not checked'),
            TextColumn::make('latestStatusCheck.created_at')->label('Checked at')->dateTime(),
        ])->recordActions([
            Action::make('verifyPayment')->label('Verify with iKhokha')->icon('heroicon-o-arrow-path')
                ->visible(fn (PaymentTransaction $record) => static::canViewAny()
                    && Gate::allows('update', $record->order) && $record->gateway === 'ikhokha' && $record->status !== 'paid')
                ->disabled(fn (PaymentTransaction $record) => ! app(IkhokhaGateway::class)->isConfigured()
                    || ! app(IkhokhaGateway::class)->validReference($record->gateway_reference))
                ->requiresConfirmation()
                ->modalDescription('Checks the saved payment reference with iKhokha. An exact paid result settles the existing order, creates its invoice and sends its receipt. This does not charge again, refund money or book delivery. Missing references must be reviewed in the merchant dashboard.')
                ->action(function (PaymentTransaction $record): void {
                    abort_unless(static::canViewAny(), 403);
                    try {
                        $outcome = app(IkhokhaReconciliationService::class)->check($record, auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Payment not checked')->body($exception->validator->errors()->first())->warning()->send();

                        return;
                    }
                    $message = match ($outcome) {
                        'paid' => 'Payment verified. Review the order for any stock, delivery or cancellation exceptions before fulfilment.',
                        'already_paid' => 'Payment was already recorded. No duplicate settlement was made.',
                        'mismatch' => 'Amounts or currency do not match. No settlement made; review the merchant dashboard.',
                        'stale' => 'The payment record changed during verification. Review it before checking again.',
                        'provider_unavailable' => 'Provider status could not be verified. No payment state changed; try later or review the merchant dashboard.',
                        default => 'Provider has not confirmed PAID. No payment state changed.',
                    };
                    $notification = Notification::make()->title('Payment verification')->body($message);
                    in_array($outcome, ['paid', 'already_paid'], true) ? $notification->success() : $notification->warning();
                    $notification->send();
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentTransactions::route('/')];
    }
}
