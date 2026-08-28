<?php

namespace App\Filament\Admin\Resources\ShippingMethods;

use App\Models\ShippingMethod;
use App\Support\StoreMoney;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Delivery methods';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Flower delivery')->description('Set only rates and areas your delivery team has agreed to serve. This does not book a DSV shipment.')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('estimated_delivery_time')->label('Delivery estimate')->required()->maxLength(255),
                Textarea::make('description')->maxLength(2000)->columnSpanFull(),
                Toggle::make('is_active')->label('Available at checkout')->default(false)
                    ->helperText('Deactivate instead of deleting to preserve historical orders.'),
                Toggle::make('requires_delivery_slot')->label('Require a delivery window')->default(false)
                    ->helperText('Create and publish windows in Delivery calendar first. With no available windows, customers cannot use this method. Capacity is per order, not per bouquet.'),
                TextInput::make('base_rate')->label('Base rate ('.StoreMoney::currency().')')->numeric()->required()->minValue(0)->maxValue(999999.99),
                TextInput::make('weight_rate')->label('Rate per catalogue weight unit')->numeric()->required()->minValue(0)->maxValue(999999.99)->default(0)
                    ->helperText('Use the same weight unit configured on your products.'),
                TextInput::make('max_weight')->label('Maximum total weight')->numeric()->required()->minValue(0)->maxValue(999999),
                TagsInput::make('postal_codes')->label('Allowed South African postal codes')->nestedRecursiveRules(['string', 'regex:/^[0-9]{4}$/'])
                    ->helperText('Enter exact four-digit codes, including leading zeros. Empty means all South African postal codes; no overseas shipping is enabled.')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            IconColumn::make('is_active')->label('Active')->boolean(),
            IconColumn::make('requires_delivery_slot')->label('Scheduled')->boolean(),
            TextColumn::make('base_rate')->formatStateUsing(fn ($state) => StoreMoney::format($state))->sortable(),
            TextColumn::make('estimated_delivery_time')->label('Delivery estimate'),
            TextColumn::make('postal_codes')->badge()->placeholder('All ZA postal codes')->limitList(4),
        ])->filters([TernaryFilter::make('is_active')->label('Active')])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShippingMethods::route('/'),
            'create' => Pages\CreateShippingMethod::route('/create'),
            'edit' => Pages\EditShippingMethod::route('/{record}/edit')];
    }
}
