<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\ShippingMethods\ShippingMethodResource;
use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Services\AnalyticsService;
use App\Services\Payments\IkhokhaGateway;
use App\Settings\GeneralSettings;
use App\Support\InventoryWorkspace;
use App\Support\StoreSetupGuide;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Gate;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Store overview';

    protected string $view = 'filament.admin.pages.dashboard';

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'A clear view of your store. A simple way to get things done.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewStore')->label('View store')->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')->url(url('/'))->openUrlInNewTab(),
            Action::make('addProduct')->label('Add product')->icon('heroicon-o-plus')
                ->visible(fn () => ProductResource::canCreate())->url(fn () => ProductResource::getUrl('create')),
        ];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $canOrders = Gate::allows('viewAny', Order::class) && Gate::allows('view', new Order);
        $canProducts = Gate::allows('viewAny', Product::class) && Gate::allows('view', new Product);
        $settings = app(GeneralSettings::class);
        $metrics = $canOrders ? app(AnalyticsService::class)->getSalesMetrics() : null;
        $tasks = $canOrders ? [
            ['label' => 'Orders to prepare', 'description' => 'Paid, with inventory confirmed', 'icon' => 'heroicon-o-shopping-bag',
                'count' => Order::workQueue('to_prepare')->count(), 'url' => OrderResource::getUrl('index', ['tab' => 'to_prepare'])],
            ['label' => 'Payment attention', 'description' => 'Unconfirmed payments to review', 'icon' => 'heroicon-o-credit-card',
                'count' => Order::workQueue('payment_attention')->count(), 'url' => OrderResource::getUrl('index', ['tab' => 'payment_attention'])],
            ['label' => 'Fulfilment exceptions', 'description' => 'Paid orders needing stock or delivery review', 'icon' => 'heroicon-o-exclamation-circle',
                'count' => Order::workQueue('exceptions')->count(), 'url' => OrderResource::getUrl('index', ['tab' => 'exceptions'])],
        ] : [];

        return [
            'storeName' => $settings->site_name,
            'storeOpen' => $settings->storefront_enabled,
            'today' => now()->timezone(config('commerce.delivery_timezone'))->format('l, j F Y'),
            'metrics' => $metrics,
            'lowStock' => $canProducts ? app(InventoryWorkspace::class)->query(status: 'low')->count() : null,
            'inventoryUrl' => Inventory::canAccess() ? Inventory::getUrl(['status' => 'low']) : null,
            'tasks' => $tasks,
            'setupGuide' => StoreReadiness::canAccess() ? app(StoreSetupGuide::class)->summary() : null,
            'orders' => $canOrders ? Order::orderByDesc('id')->limit(6)->get() : collect(),
            'ordersUrl' => $canOrders ? OrderResource::getUrl() : null,
            'productsUrl' => $canProducts ? ProductResource::getUrl() : null,
            'designUrl' => ManageGeneralSettings::canAccess() ? ManageGeneralSettings::getUrl() : null,
            'integrationsUrl' => StoreIntegrations::canAccess() ? StoreIntegrations::getUrl() : null,
            'paymentConfigured' => app(IkhokhaGateway::class)->isConfigured()
                || (filled(config('services.stripe.key')) && filled(config('services.stripe.secret'))),
            'canDelivery' => ShippingMethodResource::canViewAny(),
            'deliveryConfigured' => ShippingMethodResource::canViewAny() ? ShippingMethod::where('is_active', true)->exists() : null,
        ];
    }
}
