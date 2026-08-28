@extends('layouts.app')
@section('title', 'Saved payment references')
@include('payment_methods.styles')
@section('content')
<section class="payment-workspace" aria-labelledby="payment-page-title">
    <h1 id="payment-page-title">Saved payment references</h1>
    <p class="payment-intro">Manage references supplied by your payment provider. These are not verified saved cards, and adding a reference does not enable a payment gateway or charge money. Complete purchases through the store checkout.</p>
    @if($errors->any())
        <div class="payment-errors" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <div class="payment-grid">
        <section class="payment-panel" aria-labelledby="saved-heading">
            <h2 id="saved-heading">Your references</h2>
            @forelse($paymentMethods as $method)
                <article class="payment-item">
                    <strong>{{ $method->name }}</strong>@if($method->is_default)<span class="payment-badge">Default</span>@endif
                    <p class="payment-reference">Reference ending {{ substr($method->details, -4) }}</p>
                    <div class="payment-actions">
                        <a href="{{ route('payment_methods.edit', $method->id) }}" aria-label="Edit {{ $method->name }}">Edit</a>
                        @unless($method->is_default)
                            <form method="POST" action="{{ route('payment_methods.setDefault', $method->id) }}">@csrf<button type="submit" aria-label="Make {{ $method->name }} default">Make default</button></form>
                        @endunless
                        <form method="POST" action="{{ route('payment_methods.destroy', $method->id) }}">@csrf @method('DELETE')<button class="payment-remove" type="submit" aria-label="Remove {{ $method->name }}">Remove</button></form>
                    </div>
                </article>
            @empty
                <p>No references saved yet. You can still use the supported checkout payment options.</p>
            @endforelse
        </section>
        <section class="payment-panel" aria-labelledby="add-heading">
            <h2 id="add-heading">Add a provider reference</h2>
            <form action="{{ route('payment_methods.store') }}" method="POST">
                @csrf
                <div class="payment-field"><label for="name">Reference name</label><input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="255" required placeholder="For example, my personal account"></div>
                <div class="payment-field"><label for="details">Provider-issued reference</label><input type="text" id="details" name="details" maxlength="255" required autocomplete="off" spellcheck="false" aria-describedby="reference-help"><small id="reference-help">Use a reference beginning pm_, tok_, paypal_ or vault_. Do not enter card numbers, CVVs, passwords or API keys.</small></div>
                <label class="payment-check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', false))> Use as my default saved reference</label>
                <div class="payment-actions"><button class="payment-primary" type="submit">Save reference</button></div>
            </form>
        </section>
    </div>
</section>
@endsection
