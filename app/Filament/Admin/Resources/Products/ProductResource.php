<?php

namespace App\Filament\Admin\Resources\Products;

use App\Filament\Admin\Pages\Inventory;
use App\Filament\Admin\Pages\ProductVariantDrafts;
use App\Filament\Admin\Resources\Products\Pages\CreateProduct;
use App\Filament\Admin\Resources\Products\Pages\EditProduct;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\InventoryWorkspace;
use App\Support\StoreMoney;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $old, ?string $state): void {
                                $currentSlug = (string) ($get('slug') ?? '');
                                $oldNameSlug = Str::slug((string) ($old ?? ''));
                                if ($currentSlug !== '' && $currentSlug !== $oldNameSlug) {
                                    return;
                                }
                                $set('slug', Str::slug((string) $state));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label('Category')
                            ->options(ProductCategory::all()->pluck('name', 'id'))
                            ->searchable(),
                        Select::make('tags')
                            ->multiple()
                            ->relationship('tags', 'name')
                            ->preload(),
                        Textarea::make('description')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),

                Section::make('Pricing')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('pricing_type')
                            ->disabled(fn (?Product $record) => $record?->has_variants ?? false)
                            ->options([
                                'fixed' => 'Fixed Price',
                                'free' => 'Free',
                            ])
                            ->required()
                            ->rules(['in:fixed,free'])
                            ->helperText('Free sets the item price to zero; delivery charges may still apply. Pay-what-you-want pricing is not supported at checkout.')
                            ->default('fixed')
                            ->formatStateUsing(fn ($state) => filled($state) ? $state : 'fixed')
                            ->live(),
                        TextInput::make('price')
                            ->disabled(fn (?Product $record) => $record?->has_variants ?? false)
                            ->helperText(fn (?Product $record) => $record?->has_variants ? 'Prices are managed in Catalogue → Product variants.' : null)
                            ->required()
                            ->numeric()
                            ->minValue(0)->maxValue(99999999.99)->rules(['decimal:0,2'])
                            ->prefix(StoreMoney::currency())
                            ->visible(fn (Get $get) => in_array($get('pricing_type'), [null, '', 'fixed'], true)),
                    ]),

                Section::make('Inventory')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('inventory_count')
                            ->label('Initial on-hand stock')
                            ->disabled(fn (?Product $record) => $record !== null)
                            ->helperText('For existing products use Catalogue → Inventory. Product edits never change live stock.')
                            ->required()->default(0)
                            ->integer()->minValue(0)->maxValue(2147483647),
                        TextInput::make('low_stock_threshold')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->label('Low Stock Threshold'),
                    ]),

                Section::make('Downloadable Product')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Toggle::make('is_downloadable')
                            ->disabled(fn (?Product $record) => $record?->has_variants ?? false)
                            ->label('Is Downloadable Product')
                            ->live()
                            ->columnSpanFull(),
                        FileUpload::make('downloadable_file')
                            ->label('Product File')
                            ->disk('local')
                            ->directory('downloadable_products')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'application/zip'])
                            ->maxSize(50 * 1024)
                            ->required(fn (Get $get) => (bool) $get('is_downloadable'))
                            ->helperText('Private PDF or ZIP. Customers receive a protected link after verified payment, including free checkout.')
                            ->visible(fn (Get $get) => $get('is_downloadable'))
                            ->columnSpanFull(),
                        TextInput::make('download_limit')
                            ->label('Downloads per order item')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->helperText('Leave blank for unlimited downloads during the access period. Changes apply to newly issued access.')
                            ->visible(fn (Get $get) => $get('is_downloadable')),
                        DateTimePicker::make('expiration_time')
                            ->label('Download Expiration')
                            ->visible(fn (Get $get) => $get('is_downloadable')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('price')->state(fn (Product $record) => $record->store_price_label)->sortable(query: fn ($query, string $direction) => $query->orderByStorePrice($direction === 'desc')),
                TextColumn::make('category.name')->searchable()->sortable(),
                TextColumn::make('inventory_count')->state(fn (Product $record) => $record->has_variants ? 'Per option' : $record->inventory_count),
                // Tables\Columns\TagsColumn::make('tags.name'),
            ])
            // ->filters([
            //     Tables\Filters\SelectFilter::make('category')
            //         ->relationship('category', 'name'),
            // ])
            ->recordActions([
                EditAction::make(),
                Action::make('variants')->label('Manage options')->icon('heroicon-o-squares-plus')
                    ->url(fn (Product $record) => ProductVariantDrafts::getUrl(['product' => $record->id])),
                Action::make('inventory')->label('Manage stock')->icon('heroicon-o-cube')
                    ->visible(fn () => Inventory::canAccess())
                    ->url(fn (Product $record) => Inventory::getUrl(['product' => $record->id])),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
                BulkAction::make('export')
                    ->label('Export Selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (Collection $records) => static::export($records)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    protected static function export(Collection $records)
    {
        abort_unless(Inventory::canAccess(), 403);
        foreach ($records as $record) {
            Gate::authorize('view', $record);
        }

        return app(InventoryWorkspace::class)->export(app(InventoryWorkspace::class)->query()->whereIn('product_id', $records->pluck('id')->all()));
    }
}
