<x-mail::message>
# Your invoice is ready

Thank you for your order. You can view, print or save your invoice using the private link below.

<x-mail::panel>
Invoice: {{ $document['number'] }}<br>
Order: #{{ $document['order_id'] }}<br>
Total: {{ $document['currency'] ?: 'Currency not recorded' }} {{ number_format((float) $document['totals']['total'], 2) }}
</x-mail::panel>

<x-mail::button :url="$invoiceUrl">View invoice / Save PDF</x-mail::button>

Keep this link private. It expires after 30 days. Contact the store if you need a new link.

<x-slot:subcopy>
If the button does not work, copy this address into your browser:
[{{ $invoiceUrl }}]({{ $invoiceUrl }})
</x-slot:subcopy>
</x-mail::message>
