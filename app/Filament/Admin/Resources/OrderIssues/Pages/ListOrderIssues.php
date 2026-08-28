<?php

namespace App\Filament\Admin\Resources\OrderIssues\Pages;

use App\Filament\Admin\Resources\OrderIssues\OrderIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderIssues extends ListRecords
{
    protected static string $resource = OrderIssueResource::class;

    protected static ?string $title = 'Order support';
}
