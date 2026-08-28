<x-mail::message>
@if($kind === 'test')
# Your store email, beautifully connected

Hello {{ $recipientName }},

This is the requested administrator test of your store's shared, branded email template. It uses the same store identity, colours, logo fallback and footer as your transactional emails.

<x-mail::panel>
**This is a test only.** No order, payment, refund, password change or delivery has been created.
</x-mail::panel>

Please confirm that the logo or store name is correct, the button opens your admin portal and the message is readable on your phone. Check the spam folder if necessary. Server acceptance is not proof of inbox delivery.

<x-mail::button :url="$storeUrl.'/admin'">Open admin portal</x-mail::button>
@else
# Your store. One practical guide.

Hello {{ $recipientName }},

Here is the feature guide for your deployed store, with where to find each workflow and what still needs review before live trading.

<x-mail::panel>
**Client testing, not launch certification.** {{ $guide['blocked_count'] }} automated configuration checks currently need attention. Features that are incomplete or awaiting a provider are identified below. Reviewed {{ $guide['reviewed_at'] }}.
</x-mail::panel>

<x-mail::button :url="$storeUrl.'/admin'">Go to your store admin</x-mail::button>

## Your feature directory

@foreach($guide['modules'] as $module)
### {{ $loop->iteration }}. {{ $module['title'] }}

{{ $module['details'] }}

[Open workspace]({{ $storeUrl.$module['path'] }})

@endforeach
## Configuration snapshot

These checks show configuration only, not successful live payment, delivery or legal acceptance.

Catalogue records: **{{ $guide['product_count'] }}**. A delivery check can pass when there are no physical products; this does not prove that delivery has been configured or tested.

@foreach($guide['checks'] as $check)
- **{{ $check['label'] }}:** {{ $check['passed'] ? 'Configuration check passed.' : 'Action required. '.$check['action'] }}

@endforeach

## Before testing with real customers

@foreach($guide['acceptance'] as $step)
{{ $loop->iteration }}. {{ $step }}
@endforeach

<x-mail::button :url="$storeUrl.'/admin/store-readiness'">Review launch checklist</x-mail::button>

This is a single-store platform. It is not a claim of complete Shopify/WooCommerce parity, international compliance or zero bugs. Admin links require sign-in and the relevant permission. Never send passwords or gateway credentials by email.
@endif

<x-slot:subcopy>
You received this operational message because your account is an active store administrator. Sent individually; other administrators' addresses are not shared.

Admin portal: [{{ $storeUrl.'/admin' }}]({{ $storeUrl.'/admin' }})
</x-slot:subcopy>
</x-mail::message>
