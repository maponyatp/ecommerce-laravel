<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\ReturnManagementService;
use App\Support\AdminFormValidation;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class Returns extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 7;

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:60,1'];

    protected string $view = 'filament.admin.pages.returns';

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $returnId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin'])
            && Gate::allows('viewAny', Order::class) && Gate::allows('view', new Order);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), ['order' => 'nullable|integer|min:1', 'return' => 'nullable|integer|min:1']);
        abort_if($validator->fails(), 422, 'Invalid return reference.');
        $this->orderId = filled(request()->query('order')) ? (int) request()->query('order') : null;
        $this->returnId = filled(request()->query('return')) ? (int) request()->query('return') : null;
        abort_if($this->returnId && ! $this->orderId, 422, 'Choose the order first.');
    }

    private function order(): Order
    {
        abort_unless(static::canAccess(), 403);
        $order = Order::findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    private function record(): ?ReturnRequest
    {
        return $this->returnId ? ReturnRequest::where('order_id', $this->order()->id)->findOrFail($this->returnId) : null;
    }

    protected function getHeaderActions(): array
    {
        if (! $this->orderId) {
            return [];
        }
        if (! $this->returnId) {
            return [Action::make('openReturn')->label('Record return request')
                ->visible(fn () => Gate::allows('update', $this->order()))
                ->modalDescription('Staff intake only. This does not issue a refund, book a collection, email the customer, or restock flowers.')
                ->fillForm(fn () => ['request_key' => (string) Str::uuid(), 'return_method' => 'drop_off', 'items' => []])
                ->schema([
                    Hidden::make('request_key')->required(),
                    TextInput::make('reason')->required()->maxLength(255),
                    Textarea::make('description')->label('Private intake details')->maxLength(4000),
                    Select::make('return_method')->options(ReturnManagementService::METHODS)->required(),
                    Repeater::make('items')->minItems(1)->maxItems(50)->required()->schema([
                        Select::make('order_item_id')->label('Purchased physical item')->options(fn () => $this->order()->items()
                            ->where('is_downloadable_snapshot', false)->get()->mapWithKeys(fn ($item) => [$item->id => ($item->product_name_snapshot ?: 'Item #'.$item->id).' · purchased '.$item->quantity]))->required(),
                        TextInput::make('quantity')->integer()->minValue(1)->maxValue(9999)->required(),
                    ])->columns(2),
                ])->action(function (array $data): void {
                    $record = AdminFormValidation::run(fn () => app(ReturnManagementService::class)->open($this->order(), $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                    $this->redirect(static::getUrl(['order' => $this->orderId, 'return' => $record->id]));
                })];
        }

        return [Action::make('reviewReturn')->label('Review / record receipt')
            ->visible(fn () => Gate::allows('update', $this->order()) && $this->record()?->version !== null
                && ! in_array($this->record()?->status, ['completed', 'rejected', 'cancelled'], true))
            ->modalDescription('Record physical handling only. Completing this return does not refund money, create a credit note, send a replacement or add stock.')
            ->fillForm(function () {
                $record = $this->record();

                return ['version' => $record->version, 'status' => $record->status, 'note' => '',
                    'items' => $record->items()->get()->map(fn ($item) => ['id' => $item->id,
                        'name' => $item->product_name_snapshot.' · approved quantity '.$item->quantity,
                        'received_quantity' => $item->received_quantity, 'condition' => $item->condition, 'disposition' => $item->disposition])->all()];
            })->schema([
                Hidden::make('version')->required(),
                Select::make('status')->options(ReturnManagementService::STATUSES)->required(),
                Repeater::make('items')->addable(false)->deletable(false)->reorderable(false)->schema([
                    Hidden::make('id')->required(),
                    TextInput::make('name')->disabled()->dehydrated(false)->label('Item'),
                    TextInput::make('received_quantity')->integer()->required()->minValue(0)->maxValue(9999),
                    Select::make('condition')->options(ReturnManagementService::CONDITIONS),
                    Select::make('disposition')->label('Handling decision — no stock change')->options(ReturnManagementService::DISPOSITIONS),
                ])->columns(2),
                Textarea::make('note')->label('Private decision / handling note')->required()->maxLength(4000),
            ])->action(function (array $data): void {
                AdminFormValidation::run(fn () => app(ReturnManagementService::class)->update($this->record(), $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('Return handling updated')->body('Payment and stock were not changed.')->success()->send();
            })];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), ['status' => ['nullable', Rule::in(array_keys(ReturnManagementService::STATUSES))],
            'page' => 'nullable|integer|min:1|max:1000000', 'audit_page' => 'nullable|integer|min:1|max:1000000']);
        abort_if($validator->fails(), 422, 'Invalid return filters.');
        $status = request()->query('status') ?? '';
        $order = $this->orderId ? $this->order() : null;
        $record = $this->record();
        $returns = ReturnRequest::with('order:id,customer_email')->when($order, fn ($query) => $query->where('order_id', $order->id))
            ->when($status !== '', fn ($query) => $query->where('status', $status))->orderByDesc('id')->paginate(25)->withQueryString();
        $changes = $record?->changes()->with('actor:id,name')->orderByDesc('version')->paginate(10, ['*'], 'audit_page')
            ->appends(['order' => $this->orderId, 'return' => $this->returnId]);

        return compact('order', 'record', 'returns', 'changes', 'status');
    }
}
