<?php

namespace App\Support;

use App\Filament\Admin\Pages;
use App\Filament\Admin\Resources;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;

/** Groups navigation items without mutating inherited static resource properties. */
class AdminNavigation
{
    public static function build(NavigationBuilder $builder): NavigationBuilder
    {
        $map = [
            'Store operations' => [Resources\Orders\OrderResource::class, Pages\CustomerDirectory::class,
                Resources\PaymentTransactions\PaymentTransactionResource::class, Resources\Invoices\InvoiceResource::class, Pages\Refunds::class, Pages\Returns::class],
            'Catalogue' => [Resources\Products\ProductResource::class, Pages\Inventory::class, Resources\Categories\CategoryResource::class, Pages\ProductVariantDrafts::class],
            'Online store' => [Pages\ThemeLibrary::class, Resources\MenuResource::class, Resources\Pages\PageResource::class, Pages\ManageGeneralSettings::class, Pages\FAQ::class],
            'Delivery' => [Pages\DeliveryOperations::class, Resources\DeliverySlots\DeliverySlotResource::class, Resources\ShippingMethods\ShippingMethodResource::class],
            'Marketing & reports' => [Resources\Discounts\DiscountResource::class, Resources\Coupons\CouponResource::class, Pages\Reports::class],
            'Customer support' => [Resources\OrderIssues\OrderIssueResource::class, Pages\ChatAgentDashboard::class, Resources\ChatConversations\ChatConversationResource::class],
            'Settings' => [Pages\StoreReadiness::class, Pages\StoreIntegrations::class, Pages\StaffSecurity::class, Resources\TaxClasses\TaxClassResource::class, Resources\Users\UserResource::class],
            'Advanced' => [Pages\DropxlImport::class],
        ];
        $labels = [Pages\ManageGeneralSettings::class => 'Store design & settings', Pages\FAQ::class => 'FAQs',
            Pages\ChatAgentDashboard::class => 'Support inbox', Resources\ChatConversations\ChatConversationResource::class => 'Conversations'];
        $specs = [];
        $groups = array_fill_keys(array_keys($map), []);
        $root = [];
        foreach ($map as $group => $classes) {
            foreach ($classes as $sort => $class) {
                $specs[$class] = [$group, $sort + 1];
            }
        }
        $panel = Filament::getCurrentOrDefaultPanel();
        foreach (array_unique([...$panel->getResources(), ...$panel->getPages()]) as $class) {
            if (! $class::shouldRegisterNavigation() || ! $class::canAccess()) {
                continue;
            }
            foreach ($class::getNavigationItems() as $item) {
                $group = $specs[$class][0] ?? $item->getGroup();
                $group = $group === 'Administration' ? 'Settings' : $group;
                if (isset($specs[$class])) {
                    $item->sort($specs[$class][1]);
                }
                if (isset($labels[$class])) {
                    $item->label($labels[$class]);
                }
                if (blank($group)) {
                    $root[] = $item;
                } else {
                    $groups[$group][] = $item;
                }
            }
        }
        $navigation = [];
        foreach ($groups as $label => $items) {
            if ($items) {
                $navigation[] = NavigationGroup::make($label)->collapsed($label !== 'Store operations')
                    ->items(collect($items)->sortBy(fn ($item) => $item->getSort())->values()->all());
            }
        }

        return $builder->items($root)->groups($navigation);
    }
}
