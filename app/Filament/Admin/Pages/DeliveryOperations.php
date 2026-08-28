<?php

namespace App\Filament\Admin\Pages;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class DeliveryOperations extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Delivery operations';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.admin.pages.delivery-operations';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) && Gate::allows('viewAny', Order::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('deliveryList')->label('Open daily delivery list')->url(route('operations.delivery-list'))->openUrlInNewTab()];
    }
}
