<x-mail::message>
# New store enquiry

<p><strong>Name:</strong> {{ $enquiry['first_name'] }} {{ $enquiry['last_name'] ?? '' }}<br>
<strong>Email:</strong> {{ $enquiry['email'] }}<br>
<strong>Phone:</strong> {{ $enquiry['phone'] ?? 'Not provided' }}</p>
<p>{!! nl2br(e($enquiry['message'])) !!}</p>

Reply to this email to respond to the customer.
</x-mail::message>
