<?php

namespace App\Filament\Admin\Resources\Menus;

/**
 * Backwards-compatible route for the older resource namespace.
 *
 * The active resource is the menu-builder resource, which supplies the
 * dedicated visual editor used to create and arrange storefront navigation.
 */
class MenuResource extends \App\Filament\Admin\Resources\MenuResource
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
