<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Services\OrderFulfillmentService;
use App\Support\OrderWorkspace;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Next step')->schema([View::make('filament.admin.orders.next-step')])->columnSpanFull(),
            Section::make('Order')->description('Payment and order totals are read-only. Prepare, dispatch and deliver paid orders using the fulfilment controls.')->schema([
                Hidden::make('fulfillment_version')->required(),
                TextInput::make('customer_email')->email()->disabled(),
                TextInput::make('total_amount')->disabled(),
                TextInput::make('refund_total')->label('Externally recorded refunds')->disabled(),
                TextInput::make('currency')->disabled()->placeholder('Not recorded'),
                TextInput::make('delivery_contact_name')->label('Delivery recipient')->disabled(),
                TextInput::make('delivery_phone')->disabled(),
                TextInput::make('payment_method')->disabled(),
                Select::make('payment_status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'])->disabled(),
                TextInput::make('status')->label('Order status')->formatStateUsing(fn ($state) => OrderWorkspace::status($state))->disabled(),
                Select::make('shipping_status')->label('Fulfilment')->options(fn (?Order $record) => app(OrderFulfillmentService::class)->statusOptions($record))->required()
                    ->helperText('Move one step at a time. Pending refunds pause fulfilment. Unpaid, cancelled, stock-review and delivery-review orders cannot be dispatched.'),
                Textarea::make('shipping_address')->disabled()->columnSpanFull(),
                TextInput::make('delivery_carrier')->label('Courier / local delivery team')->maxLength(120),
                TextInput::make('supplier_tracking_number')->label('Tracking / dispatch reference')->maxLength(255),
                TextInput::make('tracking_url')->label('HTTPS tracking link (optional)')->rules(['nullable', 'url:https'])->maxLength(2048)->columnSpanFull(),
            ])->columns(2),
            Section::make('Items, gift details and activity')->schema([View::make('filament.admin.orders.operations')])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Order')->formatStateUsing(fn ($state) => '#'.$state)
                ->searchable(query: fn ($query, string $search) => $query->where('orders.id', ctype_digit(ltrim(trim($search), '#')) ? (int) ltrim(trim($search), '#') : 0))->sortable(),
            TextColumn::make('customer_email')->searchable(),
            TextColumn::make('total_amount')->formatStateUsing(fn ($state, Order $record) => $record->formatMoney($state))->sortable(),
            TextColumn::make('payment_status')->badge(),
            TextColumn::make('refund_total')->label('Recorded refunds')->formatStateUsing(fn ($state, Order $record) => $record->formatMoney($state)),
            TextColumn::make('status')->label('Order status')->formatStateUsing(fn ($state) => OrderWorkspace::status($state))->badge(),
            TextColumn::make('shipping_status')->label('Fulfilment')->formatStateUsing(fn ($state) => OrderWorkspace::FULFILMENT_LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state)))->badge(),
            TextColumn::make('delivery_scheduled_at')->label('Delivery (South Africa)')->dateTime('d M Y H:i', timezone: config('commerce.delivery_timezone'))->sortable()->placeholder('Not scheduled'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('payment_status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded']),
            SelectFilter::make('shipping_status')->label('Fulfilment')->options(['unfulfilled' => 'Unfulfilled', 'processing' => 'Preparing', 'shipped' => 'Dispatched', 'delivered' => 'Delivered', 'not_required' => 'Not required']),
            Filter::make('needs_attention')->label('Stock review')->query(fn ($query) => $query->where('status', 'payment_received_stock_review')),
            Filter::make('delivery_review')->label('Delivery booking review')->query(fn ($query) => $query->where('status', 'payment_received_delivery_review')),
            Filter::make('delivery_date')->label('Delivery date (South Africa)')->schema([DatePicker::make('date')->label('Delivery date')->rules(['date'])])
                ->query(function ($query, array $data) {
                    if (empty($data['date']) || Validator::make($data, ['date' => 'date_format:Y-m-d'])->fails()) {
                        return $query;
                    }
                    $day = Carbon::parse($data['date'], config('commerce.delivery_timezone'));

                    return $query->whereBetween('delivery_scheduled_at', [
                        $day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc(),
                    ]);
                }),
        ])->defaultSort('created_at', 'desc')->emptyStateHeading('No orders in this view')
            ->emptyStateDescription('Try All orders or clear your search and filters. New customer orders appear here after checkout.')
            ->recordActions([EditAction::make()->label('Manage')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'edit' => EditOrder::route('/{record}/edit')];
    }
}
