<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantDraft;
use App\Services\ProductVariantDraftService;
use App\Services\ProductVariantPublicationService;
use App\Support\AdminFormValidation;
use App\Support\StoreMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class ProductVariantDrafts extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Product variants';

    protected static ?string $title = 'Product variants';

    protected static ?int $navigationSort = 9;

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class, 'throttle:60,1'];

    protected string $view = 'filament.admin.pages.product-variant-drafts';

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?int $draftId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin'])
            && Gate::allows('viewAny', Product::class) && Gate::allows('view', new Product);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), ['product' => 'nullable|integer|min:1', 'draft' => 'nullable|integer|min:1']);
        abort_if($validator->fails(), 422, 'Invalid draft reference.');
        $this->productId = filled(request()->query('product')) ? (int) request()->query('product') : null;
        $this->draftId = filled(request()->query('draft')) ? (int) request()->query('draft') : null;
        abort_if($this->draftId && ! $this->productId, 422, 'Choose the parent product first.');
    }

    private function product(): Product
    {
        abort_unless(static::canAccess(), 403);
        $product = Product::findOrFail($this->productId);
        Gate::authorize('view', $product);

        return $product;
    }

    private function draft(): ?ProductVariantDraft
    {
        return $this->draftId ? ProductVariantDraft::where('product_id', $this->product()->id)->findOrFail($this->draftId) : null;
    }

    protected function getHeaderActions(): array
    {
        if (! $this->productId) {
            return [];
        }

        return [Action::make('publishVariant')->label('Publish / manage live option')
            ->visible(fn () => $this->draftId && Gate::allows('update', $this->product()))
            ->modalDescription('Copies this draft’s title and price to the live option. Publishing the first option replaces the parent’s buy button with an option selector. Stock is a physical on-hand count, not available-to-sell. Existing orders keep their purchased price. Published SKU/options cannot be replaced; create a new draft for a different option.')
            ->fillForm(function () {
                $live = ProductVariant::where('draft_id', $this->draft()->id)->first();

                return ['draft_version' => $this->draft()->version, 'version' => $live?->version ?? 0,
                    'inventory_quantity' => $live?->inventory_quantity ?? 0, 'weight' => $live?->weight ?? 0, 'active' => $live?->active ?? false];
            })
            ->schema([
                Hidden::make('draft_version')->required(), Hidden::make('version')->required(),
                TextInput::make('inventory_quantity')->label('On-hand units')->required()->integer()->minValue(0)->maxValue(9999999)->helperText('Reserved units are included. Saving stock uses conflict detection; reopen if a sale has occurred.'),
                TextInput::make('weight')->label('Packed weight per unit (kg)')->required()->numeric()->minValue(0)->maxValue(999999.99)->helperText('Used by weight-based shipping.'),
                Toggle::make('active')->label('Available for sale')->helperText('Turning this off prevents new purchases. It does not cancel existing orders. Archiving a draft alone does not unpublish its live option.'),
            ])
            ->action(function (array $data): void {
                AdminFormValidation::run(fn () => app(ProductVariantPublicationService::class)->publish($this->product(), $this->draft(), $data, auth()->user()), $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('Live option updated')->success()->send();
            }), Action::make('saveDraft')->label($this->draftId ? 'Edit draft' : 'New variant draft')
            ->visible(fn () => Gate::allows('update', $this->product()))
            ->modalDescription('Planning only. Saving does not publish a variant, change stock, or update the storefront. Archived drafts and older values remain in history.')
            ->fillForm(function () {
                $draft = $this->draft();

                return $draft ? [...app(ProductVariantDraftService::class)->values($draft), 'version' => $draft->version]
                    : ['version' => 0, 'sku' => '', 'title' => '', 'options' => ['Size' => 'Standard'], 'price' => $this->product()->price, 'archived' => false];
            })
            ->schema([
                Hidden::make('version')->required(),
                TextInput::make('sku')->label('Proposed SKU')->required()->maxLength(64)->helperText('Letters, numbers, dots, hyphens and underscores. Reserved among drafts only, including archived drafts.'),
                TextInput::make('title')->label('Variant title')->required()->maxLength(120),
                KeyValue::make('options')->keyLabel('Option name')->valueLabel('Value')->keyPlaceholder('Size or Colour')->valuePlaceholder('Large or Pink')->helperText('One to three option names. Names: 40 characters; values: 80 characters.'),
                TextInput::make('price')->label('Proposed unit price')->prefix(fn () => $this->draft()?->currency ?? StoreMoney::currency())->required()->numeric()->minValue(0)->maxValue(99999999.99),
                Toggle::make('archived')->label('Archive draft')->helperText('Hides this draft from the active list. It does not delete its history.'),
            ])
            ->action(function (array $data): void {
                $record = AdminFormValidation::run(fn () => app(ProductVariantDraftService::class)->save($this->product(), $this->draft(), $data, auth()->user()),
                    $this->getMountedActionSchema()->getStatePath());
                Notification::make()->title('Variant draft saved')->body('No live price, stock or storefront change was made.')->success()->send();
                if (! $this->draftId) {
                    $this->redirect(static::getUrl(['product' => $this->productId, 'draft' => $record->id]));
                }
            })];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $validator = Validator::make(request()->query(), [
            'search' => 'nullable|string|max:120', 'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'page' => 'nullable|integer|min:1|max:1000000', 'audit_page' => 'nullable|integer|min:1|max:1000000',
        ]);
        abort_if($validator->fails(), 422, 'Invalid catalogue filters.');
        $search = trim(request()->query('search') ?? '');
        $status = request()->query('status') ?? 'active';
        $product = $this->productId ? $this->product() : null;
        $draft = $this->draftId ? $this->draft() : null;
        $live = $draft ? ProductVariant::where('draft_id', $draft->id)->first() : null;
        $publications = $live ? DB::table('product_variant_publications')->where('product_variant_id', $live->id)->orderByDesc('id')->limit(20)->get() : collect();
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        if (! $product) {
            $products = Product::query()->when($search !== '', fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                ->orderBy('name')->paginate(25)->withQueryString();

            return compact('product', 'draft', 'products', 'search', 'status');
        }
        $drafts = ProductVariantDraft::where('product_id', $product->id)
            ->addSelect(['live_active' => ProductVariant::select('active')->whereColumn('draft_id', 'product_variant_drafts.id')->limit(1)])
            ->when($status !== 'all', fn ($query) => $query->where('archived', $status === 'archived'))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern])))
            ->orderBy('title')->orderBy('id')->paginate(25)->withQueryString()->appends(['product' => $product->id]);
        $changes = $draft?->changes()->with('actor:id,name')->orderByDesc('version')->paginate(10, ['*'], 'audit_page')
            ->withQueryString()->appends(['product' => $product->id, 'draft' => $draft->id]);

        return compact('product', 'draft', 'drafts', 'changes', 'search', 'status', 'live', 'publications');
    }
}
