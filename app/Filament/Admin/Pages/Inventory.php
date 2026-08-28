<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryManagementService;
use App\Support\AdminFormValidation;
use App\Support\InventoryWorkspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class Inventory extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:60,1'];

    protected string $view = 'filament.admin.pages.inventory';

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?int $variantId = null;

    #[Locked]
    public string $search = '';

    #[Locked]
    public string $status = 'all';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin'])
            && Gate::allows('viewAny', Product::class) && Gate::allows('view', new Product);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = Validator::make(request()->query(), [
            'product' => 'nullable|integer|min:1', 'variant' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:120', 'status' => ['nullable', Rule::in(['all', 'low', 'out', 'paused'])],
            'page' => 'nullable|integer|min:1|max:1000000', 'history_page' => 'nullable|integer|min:1|max:1000000',
        ])->validate();
        $this->productId = filled($data['product'] ?? null) ? (int) $data['product'] : null;
        $this->variantId = filled($data['variant'] ?? null) ? (int) $data['variant'] : null;
        $this->search = trim($data['search'] ?? '');
        $this->status = $data['status'] ?? 'all';
        abort_if($this->variantId && ! $this->productId, 422, 'Choose a product first.');
        if ($this->productId) {
            Gate::authorize('view', Product::findOrFail($this->productId));
            if ($this->variantId) {
                abort_unless($this->unit(), 404);
            }
        }
    }

    public function getSubheading(): ?string
    {
        return 'Receive stock, record flower wastage and review what is available after checkout reservations.';
    }

    private function unit(): ?object
    {
        if (! $this->productId) {
            return null;
        }

        return app(InventoryWorkspace::class)->query(productId: $this->productId)
            ->when($this->variantId, fn ($q) => $q->where('variant_id', $this->variantId), fn ($q) => $q->whereNull('variant_id'))->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjustStock')->label('Adjust stock')->icon('heroicon-o-adjustments-horizontal')
                ->modalSubmitActionLabel('Save stock adjustment')
                ->visible(fn () => $this->unit() && Gate::allows('update', Product::findOrFail($this->productId)))
                ->modalDescription('On hand includes checkout reservations. Adjust by adds or removes units; Set to records a physical count. This does not refund, cancel, restock returns, or publish an option.')
                ->fillForm(fn () => ['expected_quantity' => $this->unit()?->on_hand, 'mode' => 'adjust', 'reason_code' => 'received'])
                ->schema([
                    TextInput::make('expected_quantity')->label('On hand when opened')->disabled()->dehydrated()->required()->integer(),
                    Select::make('mode')->options(['adjust' => 'Adjust by (+ / − units)', 'set' => 'Set to counted quantity'])->required(),
                    TextInput::make('quantity')->label('Units')->integer()->required()->minValue(-2147483647)->maxValue(2147483647),
                    Select::make('reason_code')->label('Reason')->options(InventoryManagementService::REASONS)->required(),
                    Textarea::make('note')->label('Supplier reference / staff explanation')->maxLength(160),
                ])
                ->action(function (array $data): void {
                    abort_unless(static::canAccess(), 403);
                    $code = Validator::make($data, ['reason_code' => ['required', Rule::in(array_keys(InventoryManagementService::REASONS))]])->validate()['reason_code'];
                    $data['reason'] = InventoryManagementService::REASONS[$code].(filled($data['note'] ?? null) ? ': '.trim($data['note']) : '');
                    AdminFormValidation::run(fn () => app(InventoryManagementService::class)->adjust(Product::findOrFail($this->productId), $this->variantId, $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                    Notification::make()->title('Inventory reviewed')->body('Any stock change and staff reason were saved together.')->success()->send();
                }),
            Action::make('exportInventory')->label('Export this view')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->action(function () {
                    abort_unless(static::canAccess(), 403);
                    $query = app(InventoryWorkspace::class)->query($this->search, $this->status, $this->productId);
                    if ($this->variantId) {
                        $query->where('variant_id', $this->variantId);
                    }

                    return app(InventoryWorkspace::class)->export($query);
                }),
        ];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $workspace = app(InventoryWorkspace::class);
        $unit = $this->unit();
        $query = $workspace->query($this->search, $this->status, $this->productId);
        if ($this->variantId) {
            $query->where('variant_id', $this->variantId);
        }
        $rows = $query->orderBy('name')->orderBy('product_id')->orderBy('variant_id')->paginate(25)->withQueryString();
        $history = $unit ? InventoryLog::where('product_id', $this->productId)
            ->when($this->variantId, fn ($q) => $q->where('product_variant_id', $this->variantId), fn ($q) => $q->whereNull('product_variant_id'))
            ->orderByDesc('id')->paginate(15, ['*'], 'history_page')->withQueryString() : null;
        $actors = $history ? User::whereIn('id', $history->where('reference_type', User::class)->pluck('reference_id'))->pluck('name', 'id') : collect();

        return ['rows' => $rows, 'unit' => $unit, 'history' => $history, 'actors' => $actors,
            'lowCount' => $workspace->query(status: 'low')->count(), 'outCount' => $workspace->query(status: 'out')->count()];
    }
}
