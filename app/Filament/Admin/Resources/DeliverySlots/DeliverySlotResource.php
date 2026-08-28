<?php

namespace App\Filament\Admin\Resources\DeliverySlots;

use App\Models\DeliverySlot;
use App\Models\ShippingMethod;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeliverySlotResource extends Resource
{
    protected static ?string $model = DeliverySlot::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Delivery calendar';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Delivery window')->description('All times are South African time (UTC+02:00). Publish only dates your team can fulfil. Dates without published windows are unavailable; unpublish all windows on a date to close it to new bookings.')->schema([
                Hidden::make('version')->default(0)->required(),
                Select::make('shipping_method_id')->label('Delivery method')->options(fn () => ShippingMethod::pluck('name', 'id'))
                    ->searchable()->required()->helperText('Enable “Require a delivery window” on this delivery method after publishing your calendar.'),
                TextInput::make('capacity')->label('Maximum deliveries / orders')->integer()->required()->minValue(1)->maxValue(10000)
                    ->helperText('One order uses one place, regardless of bouquet quantity. Capacity is separate for each window and method.'),
                DateTimePicker::make('starts_at')->label('Window starts (South Africa)')->timezone(config('commerce.delivery_timezone'))->seconds(false)->required(),
                DateTimePicker::make('ends_at')->label('Window ends (South Africa)')->timezone(config('commerce.delivery_timezone'))->seconds(false)->required()->after('starts_at'),
                DateTimePicker::make('booking_closes_at')->label('Last booking time (South Africa)')->timezone(config('commerce.delivery_timezone'))->seconds(false)->required()->beforeOrEqual('starts_at'),
                Toggle::make('is_active')->label('Published for new bookings')->default(false)
                    ->helperText('Unpublishing does not cancel paid bookings or unexpired checkout holds. Booked dates and cutoffs cannot be edited.'),
            ])->columns(2),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'bookings as occupied_count' => fn ($query) => $query->occupying(),
            'bookings as confirmed_count' => fn ($query) => $query->where('status', 'confirmed'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('shippingMethod.name')->label('Delivery method'),
            TextColumn::make('starts_at')->label('Starts (South Africa)')->dateTime('d M Y H:i', timezone: config('commerce.delivery_timezone'))->sortable(),
            TextColumn::make('ends_at')->label('Ends')->dateTime('H:i', timezone: config('commerce.delivery_timezone')),
            TextColumn::make('booking_closes_at')->label('Cutoff')->dateTime('d M H:i', timezone: config('commerce.delivery_timezone')),
            TextColumn::make('capacity')->label('Capacity'),
            TextColumn::make('occupied_count')->label('Booked + held'),
            TextColumn::make('confirmed_count')->label('Confirmed'),
            IconColumn::make('is_active')->label('Published')->boolean(),
        ])->filters([
            Filter::make('upcoming')->label('Upcoming windows')->query(fn ($query) => $query->where('ends_at', '>', now()))->default(),
            SelectFilter::make('shipping_method_id')->label('Delivery method')->options(fn () => ShippingMethod::pluck('name', 'id')),
        ])->defaultSort('starts_at')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDeliverySlots::route('/'),
            'create' => Pages\CreateDeliverySlot::route('/create'),
            'edit' => Pages\EditDeliverySlot::route('/{record}/edit')];
    }
}
