<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_number')->label('Invoice')->searchable()->copyable(),
            TextColumn::make('order.id')->label('Order')->formatStateUsing(fn ($state) => '#'.$state),
            TextColumn::make('order.customer_email')->label('Customer')->searchable(),
            TextColumn::make('total_amount')->formatStateUsing(fn ($state, Invoice $record) => $record->order->formatMoney($state))->sortable(),
            TextColumn::make('payment_status')->badge(),
            TextColumn::make('invoice_date')->date()->sortable(),
        ])->defaultSort('invoice_date', 'desc')->recordActions([
            Action::make('printInvoice')->label('View / Print')->icon('heroicon-o-printer')
                ->url(fn (Invoice $record) => URL::temporarySignedRoute('invoices.print', now()->addHour(), ['invoice' => $record]))
                ->openUrlInNewTab(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
