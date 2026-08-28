<?php

namespace App\Filament\Admin\Resources;

use Biostate\FilamentMenuBuilder\Filament\Resources\MenuResource as BaseMenuResource;
use Filament\Tables\Table;

class MenuResource extends BaseMenuResource
{
    public static function getNavigationGroup(): ?string
    {
        return 'Online store';
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateIcon('heroicon-o-bars-3')
            ->emptyStateHeading('Build your storefront navigation')
            ->emptyStateDescription('Create a menu first, then use the visual builder to add links, categories, and nested items.');
    }
}
