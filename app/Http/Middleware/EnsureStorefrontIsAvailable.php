<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorefrontIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOperationalRoute($request) || app(GeneralSettings::class)->storefront_enabled) {
            return $next($request);
        }

        $settings = app(GeneralSettings::class);

        return response()
            ->view('storefront.unavailable', compact('settings'), Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '3600');
    }

    private function isOperationalRoute(Request $request): bool
    {
        // Existing payments must settle even while new shopping is paused.
        // Return/confirmation/document routes retain their signature checks.
        return $request->is(
            'admin', 'admin/*', 'health', 'livewire', 'livewire/*',
            'payments/ikhokha/webhook', 'payments/ikhokha/return/*',
            'checkout/confirmation/*', 'invoice/*/print',
            'downloads/order-items/*',
        );
    }
}
