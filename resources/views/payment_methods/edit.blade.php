@extends('layouts.app')
@section('title', 'Edit saved payment reference')
@include('payment_methods.styles')
@section('content')
<section class="payment-workspace" aria-labelledby="payment-page-title">
    <h1 id="payment-page-title">Edit saved reference</h1>
    <p class="payment-intro">Update the label or replace a provider-issued reference. This does not verify a payment method, change a provider account or charge money.</p>
    @if($errors->any())
        <div class="payment-errors" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <section class="payment-panel">
        <form action="{{ route('payment_methods.update', $paymentMethod->id) }}" method="POST">
            @csrf
            <div class="payment-field"><label for="name">Reference name</label><input id="name" name="name" type="text" value="{{ old('name', $paymentMethod->name) }}" maxlength="255" required></div>
            <div class="payment-field"><label for="details">Replacement provider reference</label><input id="details" name="details" type="text" value="" maxlength="255" autocomplete="off" spellcheck="false" aria-describedby="reference-help"><small id="reference-help">Leave blank to keep the saved reference ending {{ substr($paymentMethod->details, -4) }}. Never enter a card number or CVV. Only provider-issued references are supported.</small></div>
            <input type="hidden" name="is_default" value="0">
            <label class="payment-check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $paymentMethod->is_default))> Use as my default saved reference</label>
            <div class="payment-actions"><button class="payment-primary" type="submit">Save changes</button><a href="{{ route('payment_methods.index') }}">Cancel</a></div>
        </form>
    </section>
</section>
@endsection
