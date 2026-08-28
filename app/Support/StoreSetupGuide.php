<?php

namespace App\Support;

use App\Filament\Admin\Pages\ManageGeneralSettings;
use App\Filament\Admin\Pages\StoreIntegrations;
use App\Filament\Admin\Pages\StoreReadiness;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\ShippingMethods\ShippingMethodResource;
use App\Models\Product;
use App\Services\StoreReadinessService;

class StoreSetupGuide
{
    public function summary(?array $report = null): array
    {
        abort_unless(StoreReadiness::canAccess(), 403);
        $report ??= app(StoreReadinessService::class)->report();
        $checks = collect($report['checks'])->keyBy('id');
        $brand = app(StoreBranding::class)->current();
        $hasProducts = Product::exists();
        $steps = [
            $this->step('contact', 'Set your customer contact', 'Set a real monitored support email. Add your phone and address if used. Example email addresses cannot receive enquiries.', $checks['contact_email']['passed'], 'Update contact details', $this->settingsUrl('contact')),
            $this->step('business', 'Complete business & invoice details', 'Enter the legal seller details and confirm VAT status. Tax rules still need accountant review.', $checks['seller']['passed'] && $checks['vat_status']['passed'], 'Complete invoice details', $this->settingsUrl('business')),
            $this->step('branding', 'Add your logo & colours', 'Upload a PNG or JPEG for consistent storefront, admin, email and invoice branding. A text name remains the fallback.', filled($brand['name']) && filled($brand['logo_url']), 'Edit branding', $this->settingsUrl('branding')),
            $this->step('catalogue', 'Add your products', 'Add products, photos, prices and stock. Review purchasable options before inviting customers.', $hasProducts, $hasProducts ? 'Review products' : 'Add first product',
                $hasProducts ? (ProductResource::canViewAny() ? ProductResource::getUrl() : null) : (ProductResource::canCreate() ? ProductResource::getUrl('create') : null)),
            $this->step('payments', 'Connect payments', 'Enter your merchant credentials, then complete a merchant-approved payment test. Saving credentials is not payment verification.', $checks['payments']['passed'], 'Connect payments', StoreIntegrations::canAccess() ? StoreIntegrations::getUrl() : null),
            $this->step('delivery', 'Review delivery', 'Physical products need delivery rates, coverage and capacity. DSV credentials alone do not book deliveries. Digital-only stores need no shipping method.', $hasProducts && $checks['delivery']['passed'], 'Review delivery methods', ShippingMethodResource::canViewAny() ? ShippingMethodResource::getUrl() : null),
        ];

        return ['steps' => $steps, 'complete' => collect($steps)->where('complete', true)->count(), 'total' => count($steps),
            'next' => collect($steps)->first(fn ($step) => ! $step['complete'] && filled($step['url']))];
    }

    public function checkLink(string $id): ?array
    {
        return match ($id) {
            'contact_email' => ['url' => $this->settingsUrl('contact'), 'label' => 'Update contact details'],
            'seller', 'vat_status' => ['url' => $this->settingsUrl('business'), 'label' => 'Review business & invoices'],
            'payments' => StoreIntegrations::canAccess() ? ['url' => StoreIntegrations::getUrl(), 'label' => 'Manage payment credentials'] : null,
            'delivery' => ShippingMethodResource::canViewAny() ? ['url' => ShippingMethodResource::getUrl(), 'label' => 'Review delivery methods'] : null,
            default => null,
        };
    }

    private function settingsUrl(string $tab): ?string
    {
        return ManageGeneralSettings::canAccess() ? ManageGeneralSettings::getUrl(['tab' => $tab]) : null;
    }

    private function step(string $id, string $label, string $description, bool $complete, string $action, ?string $url): array
    {
        return compact('id', 'label', 'description', 'complete', 'action', 'url');
    }
}
