<?php

namespace App\Filament\Admin\Resources\PaymentTransactions\Pages;

use App\Filament\Admin\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Http\Middleware\PrivateCustomerDirectory;
use App\Services\Payments\IkhokhaGateway;
use Filament\Resources\Pages\ListRecords;

class ListPaymentTransactions extends ListRecords
{
    protected static string $resource = PaymentTransactionResource::class;

    protected static string|array $routeMiddleware = [PrivateCustomerDirectory::class];

    public function getSubheading(): ?string
    {
        return app(IkhokhaGateway::class)->isConfigured()
            ? 'Verify missed iKhokha callbacks using the saved provider reference. This is not a refund or cancellation tool.'
            : 'iKhokha is not configured. Add merchant API credentials on the server before accepting payments or verifying transactions. Refunds and DSV bookings are not enabled.';
    }
}
