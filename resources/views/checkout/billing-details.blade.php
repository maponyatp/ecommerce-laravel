@php($billingRequired = app(\App\Settings\GeneralSettings::class)->invoice_vat_status === 'registered')
<section class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6" aria-labelledby="billing-heading">
    <div class="px-6 py-4 border-b border-gray-100">
        <h2 id="billing-heading" class="text-lg font-semibold text-gray-900">Invoice details</h2>
        <p class="text-sm text-gray-500 mt-1">Use the buyer’s details, not the gift recipient’s. {{ $billingRequired ? 'Required for your invoice.' : 'Optional; complete both name and address if you need them on your invoice.' }}</p>
    </div>
    <div class="px-6 py-5 space-y-4">
        <div><label for="billing_name" class="block text-sm font-medium text-gray-700 mb-1.5">Full name / legal business name{{ $billingRequired ? ' *' : '' }}</label>
        <input id="billing_name" name="billing_name" value="{{ old('billing_name') }}" autocomplete="billing name" maxlength="255" @required($billingRequired) class="w-full px-4 py-2.5 border border-gray-300 rounded-lg"></div>
        <div><label for="billing_address" class="block text-sm font-medium text-gray-700 mb-1.5">Billing address, city, postal code and country{{ $billingRequired ? ' *' : '' }}</label>
        <textarea id="billing_address" name="billing_address" autocomplete="billing street-address" rows="3" maxlength="1000" @required($billingRequired) class="w-full px-4 py-2.5 border border-gray-300 rounded-lg">{{ old('billing_address') }}</textarea></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="billing_is_vat_vendor" value="1" @checked(old('billing_is_vat_vendor'))> The buyer is a South African VAT-registered vendor</label>
        <div><label for="billing_vat_number" class="block text-sm font-medium text-gray-700 mb-1.5">Buyer’s VAT number (required for VAT vendors)</label>
        <input id="billing_vat_number" name="billing_vat_number" value="{{ old('billing_vat_number') }}" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" aria-describedby="billing-vat-help" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg">
        <p id="billing-vat-help" class="text-xs text-gray-500 mt-1">Enter the 10-digit SARS VAT number. Leave blank for non-VAT-registered buyers.</p></div>
    </div>
</section>
