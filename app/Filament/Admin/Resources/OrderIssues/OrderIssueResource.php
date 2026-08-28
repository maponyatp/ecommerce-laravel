<?php

namespace App\Filament\Admin\Resources\OrderIssues;

use App\Models\OrderIssue;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderIssueResource extends Resource
{
    protected static ?string $model = OrderIssue::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Order support';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        return (string) OrderIssue::whereNotNull('active_order_id')->count();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case details and conversation')->schema([View::make('filament.admin.orders.support-case')])->columnSpanFull(),
            Section::make('Respond to customer')->description('Responses appear on the private order-support page. No email is sent. Closing a case does not refund a payment, replace flowers, restock inventory or approve a return.')->schema([
                Hidden::make('version')->required(),
                Select::make('status')->options(OrderIssue::STATUSES)->required(),
                Textarea::make('public_message')->label('Customer-visible response')->maxLength(4000)->rows(4),
                Textarea::make('internal_note')->label('Private staff note')->maxLength(4000)->rows(3),
            ])->disabled(fn (?OrderIssue $record) => $record?->status === 'resolved')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Case')->formatStateUsing(fn ($state) => '#'.$state)->sortable(),
            TextColumn::make('order_id')->label('Order')->searchable()->sortable(),
            TextColumn::make('order.customer_email')->label('Buyer')->searchable(),
            TextColumn::make('category')->formatStateUsing(fn ($state) => OrderIssue::CATEGORIES[$state] ?? $state)->wrap(),
            TextColumn::make('status')->formatStateUsing(fn ($state) => OrderIssue::STATUSES[$state] ?? $state)->badge(),
            TextColumn::make('updated_at')->label('Last activity')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(OrderIssue::STATUSES)])
            ->defaultSort('updated_at', 'desc')->recordActions([EditAction::make()->label('Review')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrderIssues::route('/'), 'edit' => Pages\EditOrderIssue::route('/{record}/edit')];
    }
}
