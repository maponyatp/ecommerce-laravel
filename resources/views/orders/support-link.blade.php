<div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
    <h2 class="font-semibold text-gray-950">Need help with this order?</h2>
    <p class="mt-2 text-sm text-gray-600">Report damaged flowers, a missing delivery or another order concern. Your request will be reviewed by the shop; submitting it does not automatically approve a refund or replacement.</p>
    <a href="{{ URL::temporarySignedRoute('order-support.show', now()->addMinutes(30), ['order' => $order->id]) }}" class="mt-3 inline-flex rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Open order support</a>
</div>
