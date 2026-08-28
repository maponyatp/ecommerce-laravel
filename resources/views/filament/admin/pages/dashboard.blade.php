<x-filament-panels::page>
    <div class="store-workspace">
        <div class="store-context">
            <span class="store-context-name"><x-filament::icon icon="heroicon-o-building-storefront" /> {{ $storeName }}</span>
            <span class="store-status {{ $storeOpen ? 'is-open' : 'is-closed' }}"><span aria-hidden="true"></span>{{ $storeOpen ? 'Storefront visible' : 'Storefront paused' }}</span>
            <span class="store-date">{{ $today }}</span>
        </div>

        @if ($setupGuide)
            <section class="store-panel" aria-label="Setup guide">
                <div class="store-panel-heading"><div><p class="store-eyebrow">Your next step</p><h2>{{ $setupGuide['next']['label'] ?? 'Review your launch checks' }}</h2><p class="store-section-description">{{ $setupGuide['complete'] }} of {{ $setupGuide['total'] }} setup steps configured. {{ $setupGuide['next']['description'] ?? 'Complete the manual acceptance checks before launching.' }}</p></div>
                <a class="store-design-button" href="{{ $setupGuide['next']['url'] ?? \App\Filament\Admin\Pages\StoreReadiness::getUrl() }}">{{ $setupGuide['next']['action'] ?? 'Review launch checks' }} <x-filament::icon icon="heroicon-o-arrow-right" /></a></div>
                <div class="store-panel-footer"><a class="store-text-link" href="{{ \App\Filament\Admin\Pages\StoreReadiness::getUrl() }}">Open setup guide &amp; launch checks →</a></div>
            </section>
        @endif

        @if ($metrics)
            <section class="store-metrics" aria-label="Store performance in the last 30 days">
                <article class="store-metric">
                    <span class="store-metric-label">Paid order totals <span>{{ \App\Support\StoreMoney::currency() }}</span></span>
                    <strong>{{ \App\Support\StoreMoney::format($metrics['total_revenue']) }}</strong>
                    <span class="store-metric-note">Last 30 days · includes order charges</span>
                </article>
                <article class="store-metric">
                    <span class="store-metric-label">Paid orders <x-filament::icon icon="heroicon-o-shopping-bag" /></span>
                    <strong>{{ number_format($metrics['order_count']) }}</strong>
                    <span class="store-metric-note">{{ \App\Support\StoreMoney::currency() }} orders · last 30 days</span>
                </article>
                <article class="store-metric">
                    <span class="store-metric-label">Average order <x-filament::icon icon="heroicon-o-chart-bar" /></span>
                    <strong>{{ \App\Support\StoreMoney::format($metrics['avg_order_value']) }}</strong>
                    <span class="store-metric-note">Per paid order · last 30 days</span>
                </article>
                @if ($lowStock !== null)
                    <a class="store-metric store-metric-link" href="{{ $inventoryUrl }}">
                        <span class="store-metric-label">Low-stock items <x-filament::icon icon="heroicon-o-cube" /></span>
                        <strong>{{ number_format($lowStock) }}</strong>
                        <span class="store-metric-note">Includes options and checkout holds <span aria-hidden="true">↗</span></span>
                    </a>
                @endif
            </section>
            @if ($metrics['excluded_currency_orders'])
                <p class="store-data-note">{{ $metrics['excluded_currency_orders'] }} paid orders in another or unrecorded currency are excluded. Totals are not net revenue after refunds.</p>
            @endif
        @endif

        <div class="store-main-grid">
            <section class="store-panel store-operations" aria-labelledby="operations-heading">
                <div class="store-panel-heading"><div><p class="store-eyebrow">Your daily workspace</p><h2 id="operations-heading">What needs attention</h2></div><span class="store-icon-tile"><x-filament::icon icon="heroicon-o-clipboard-document-check" /></span></div>
                @forelse ($tasks as $task)
                    <a class="store-task" href="{{ $task['url'] }}">
                        <span class="store-task-icon"><x-filament::icon :icon="$task['icon']" /></span>
                        <span class="store-task-copy"><strong>{{ $task['label'] }}</strong><span>{{ $task['description'] }}</span></span>
                        <span class="store-task-count {{ $task['count'] ? 'has-items' : '' }}">{{ number_format($task['count']) }}</span>
                        <x-filament::icon icon="heroicon-o-chevron-right" class="store-chevron" />
                    </a>
                @empty
                    <p class="store-panel-note">Your account does not have access to order operations. Use the navigation to open your available tools.</p>
                @endforelse
                <div class="store-panel-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Each card opens its matching order queue. Counts reflect current records.</span></div>
            </section>

            <section class="store-panel store-setup" aria-labelledby="setup-heading">
                <div class="store-panel-heading"><div><p class="store-eyebrow">Build with confidence</p><h2 id="setup-heading">Store setup</h2></div><x-filament::icon icon="heroicon-o-adjustments-horizontal" class="store-setup-icon" /></div>
                <div class="store-setup-row"><span class="store-setup-indicator {{ $paymentConfigured ? 'is-configured' : '' }}"><x-filament::icon :icon="$paymentConfigured ? 'heroicon-o-check' : 'heroicon-o-exclamation-triangle'" /></span><div><strong>Payment credentials</strong><p>{{ $paymentConfigured ? 'Configured — verify a real payment before launch.' : 'Setup needed before accepting paid orders.' }}</p>@if ($integrationsUrl)<a class="store-text-link" href="{{ $integrationsUrl }}">Manage connections →</a>@endif</div></div>
                @if ($canDelivery)
                    <a class="store-setup-row" href="{{ \App\Filament\Admin\Resources\ShippingMethods\ShippingMethodResource::getUrl() }}"><span class="store-setup-indicator {{ $deliveryConfigured ? 'is-configured' : '' }}"><x-filament::icon icon="heroicon-o-truck" /></span><div><strong>Delivery methods</strong><p>{{ $deliveryConfigured ? 'Active methods — review rates and coverage.' : 'Add a delivery method and service area.' }}</p></div><x-filament::icon icon="heroicon-o-chevron-right" class="store-chevron" /></a>
                @endif
                @if ($designUrl)
                    <a class="store-setup-row" href="{{ $designUrl }}"><span class="store-setup-indicator is-design"><x-filament::icon icon="heroicon-o-swatch" /></span><div><strong>Make the store yours</strong><p>Logo, colours, homepage and store details.</p></div><x-filament::icon icon="heroicon-o-chevron-right" class="store-chevron" /></a>
                @endif
                <p class="store-setup-disclaimer">Configuration is not launch approval. Payments, delivery and refunds still need end-to-end verification.</p>
            </section>
        </div>

        @if ($ordersUrl)
            <section class="store-panel store-recent" aria-labelledby="recent-orders-heading">
                <div class="store-panel-heading"><div><h2 id="recent-orders-heading">Recent orders</h2><p class="store-section-description">Your latest customer purchases and checkout attempts.</p></div><a class="store-text-link" href="{{ $ordersUrl }}">View all orders <span aria-hidden="true">→</span></a></div>
                @if ($orders->isNotEmpty())
                    <div class="store-table-scroll" tabindex="0" role="region" aria-label="Recent orders table">
                        <table class="store-orders-table"><thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Date</th><th scope="col">Payment</th><th scope="col">Fulfilment</th><th scope="col" class="store-align-end">Total</th></tr></thead><tbody>
                        @foreach ($orders as $order)
                            <tr><th scope="row"><a href="{{ \App\Filament\Admin\Resources\Orders\OrderResource::getUrl('edit', ['record' => $order]) }}">#{{ $order->id }}</a></th><td class="store-customer-cell">{{ $order->customer_email ?: 'Customer not recorded' }}</td><td>{{ \Illuminate\Support\Carbon::parse($order->order_date)->timezone(config('commerce.delivery_timezone'))->format('d M, Y') }}</td><td><span class="store-payment-state {{ $order->payment_status === 'paid' ? 'is-paid' : '' }}">{{ ucfirst(str_replace('_', ' ', $order->payment_status ?: 'Unknown')) }}</span></td><td>{{ ucfirst(str_replace('_', ' ', $order->shipping_status ?: 'Not recorded')) }}</td><td class="store-align-end">{{ $order->formatMoney($order->total_amount) }}</td></tr>
                        @endforeach
                        </tbody></table>
                    </div>
                @else
                    <div class="store-empty"><span class="store-empty-icon"><x-filament::icon icon="heroicon-o-shopping-bag" /></span><h3>A fresh start for your store</h3><p>Your orders will appear here as customers begin shopping.</p><a class="store-text-link" href="{{ url('/') }}" target="_blank" rel="noopener noreferrer">Preview your storefront <span aria-hidden="true">↗</span></a></div>
                @endif
            </section>
        @endif

        @if ($designUrl)
            <section class="store-design-card" aria-label="Storefront customisation"><div class="store-design-art" aria-hidden="true"><x-filament::icon icon="heroicon-o-paint-brush" /></div><div><p class="store-eyebrow">Your brand, beautifully presented</p><h2>A storefront that feels like your business.</h2><p>Keep your identity consistent with central logo, colour and homepage controls.</p></div><a class="store-design-button" href="{{ $designUrl }}">Customise store <x-filament::icon icon="heroicon-o-arrow-right" /></a></section>
        @endif
    </div>
</x-filament-panels::page>
