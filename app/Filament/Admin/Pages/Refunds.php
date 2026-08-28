<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Order;
use App\Models\Refund;
use App\Services\RefundManagementService;
use App\Support\AdminFormValidation;
use App\Support\RefundAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class Refunds extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationLabel = 'Refunds & credits';

    protected static ?string $title = 'Refunds & credit notes';

    protected static string|\UnitEnum|null $navigationGroup = 'Store operations';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:60,1'];

    protected string $view = 'filament.admin.pages.refunds';

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $refundId = null;

    public static function canAccess(): bool
    {
        return RefundAccess::view(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = Validator::make(request()->query(), ['order' => 'nullable|integer|min:1', 'refund' => 'nullable|integer|min:1'])->validate();
        $this->orderId = filled($data['order'] ?? null) ? (int) $data['order'] : null;
        $this->refundId = filled($data['refund'] ?? null) ? (int) $data['refund'] : null;
        abort_if($this->refundId && ! $this->orderId, 422, 'Choose an order first.');
    }

    private function order(): ?Order
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->orderId) {
            return null;
        }
        $order = Order::findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    private function record(): ?Refund
    {
        return $this->refundId ? Refund::where('order_id', $this->order()->id)->findOrFail($this->refundId) : null;
    }

    protected function getHeaderActions(): array
    {
        $actions = [Action::make('export')->label('Export recorded refunds')->color('gray')
            ->url(fn () => route('operations.refunds.export', array_filter(['order' => $this->orderId])))];
        if (! $this->orderId) {
            return $actions;
        }
        if (! $this->refundId) {
            $actions[] = Action::make('requestRefund')->label('Request refund')
                ->visible(fn () => RefundAccess::manage(auth()->user(), $this->order()))
                ->modalDescription('Reserve an amount for refund review. This does not move money or issue a credit note. Pending requests pause fulfilment. Check the agreed tax adjustment; no tax rate is guessed.')
                ->fillForm(fn () => ['request_key' => (string) Str::uuid(), 'tax_amount' => '0.00'])
                ->schema([Hidden::make('request_key')->required(),
                    TextInput::make('amount')->label('Refund total (ZAR, including tax)')->required()->inputMode('decimal'),
                    TextInput::make('tax_amount')->label('Tax included in this refund (ZAR)')->required()->inputMode('decimal')
                        ->helperText('Use the original invoice and reviewed adjustment. Use 0.00 for a non-VAT seller.'),
                    Textarea::make('reason')->label('Customer-facing reason / goods being credited')->required()->minLength(5)->maxLength(255)])
                ->action(function (array $data): void {
                    $refund = AdminFormValidation::run(fn () => app(RefundManagementService::class)->request($this->order(), $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                    $this->redirect(static::getUrl(['order' => $this->orderId, 'refund' => $refund->id]));
                });

            return $actions;
        }
        $canChange = fn () => RefundAccess::manage(auth()->user(), $this->order()) && $this->record()->version !== null && $this->record()->status === 'pending';
        $actions[] = Action::make('recordExternal')->label('Record completed external refund')->visible($canChange)
            ->modalDescription('First verify the refund completed in the original payment provider. This action records staff-checked evidence; it does not call a refund API. It issues an immutable credit note and updates recorded refund totals. It does not restock, send email or cancel a courier.')
            ->fillForm(fn () => ['version' => $this->record()->version, 'amount' => $this->record()->amount, 'tax_amount' => $this->record()->tax_amount, 'confirmed' => false])
            ->schema([TextInput::make('version')->label('Saved revision')->disabled()->dehydrated()->required(),
                TextInput::make('amount')->label('Agreed refund total (ZAR)')->disabled()->dehydrated(false),
                TextInput::make('tax_amount')->label('Included tax adjustment (ZAR)')->disabled()->dehydrated(false),
                TextInput::make('external_reference')->label('Provider refund reference (not the original payment ID)')->required()->maxLength(120),
                DateTimePicker::make('completed_at')->label('Provider completion time (South Africa)')->timezone('Africa/Johannesburg')->seconds(false)->required(),
                Textarea::make('evidence_note')->label('Private evidence checked / reconciliation note')->required()->minLength(10)->maxLength(2000)
                    ->helperText('Record how you checked the completed refund. Do not enter card numbers, account details or passwords.'),
                Checkbox::make('confirmed')->label('I verified the completed refund, original payment, recipient, amount and tax adjustment.')->accepted()])
            ->action(function (array $data): void {
                AdminFormValidation::run(fn () => app(RefundManagementService::class)->recordExternal($this->record(), $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('External refund recorded; credit note issued')->body('No API refund, restock or email was performed.')->success()->send();
            });
        $actions[] = Action::make('cancelRequest')->label('Cancel request')->color('gray')->visible($canChange)
            ->modalDescription('Cancel only if no money has been refunded externally. This releases the reserved refund amount; it cannot reverse an actual payment refund.')
            ->fillForm(fn () => ['version' => $this->record()->version])
            ->schema([TextInput::make('version')->disabled()->dehydrated()->required(),
                Textarea::make('note')->label('Cancellation reason')->required()->minLength(5)->maxLength(2000),
                Checkbox::make('no_refund')->label('I verified that no external refund occurred.')->accepted()])
            ->action(function (array $data): void {
                AdminFormValidation::run(fn () => app(RefundManagementService::class)->cancel($this->record(), (int) $data['version'], $data['note'], auth()->user()), $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('Refund request cancelled')->success()->send();
            });

        return $actions;
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $filters = Validator::make(request()->query(), ['status' => ['nullable', Rule::in(array_keys(RefundManagementService::STATUSES))],
            'page' => 'nullable|integer|min:1|max:1000000', 'history_page' => 'nullable|integer|min:1|max:1000000'])->validate();
        $status = $filters['status'] ?? '';
        $order = $this->order();
        $record = $this->record();
        $balance = $order ? app(RefundManagementService::class)->balances($order) : null;
        $refunds = Refund::with(['order:id,customer_email', 'creditNote'])->when($order, fn ($q) => $q->where('order_id', $order->id))
            ->when(filled($status), fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(25)->withQueryString();
        $changes = $record?->changes()->with('actor:id,name')->orderByDesc('version')->paginate(10, ['*'], 'history_page')
            ->appends(['order' => $this->orderId, 'refund' => $this->refundId]);

        return compact('order', 'record', 'balance', 'refunds', 'changes', 'status');
    }
}
