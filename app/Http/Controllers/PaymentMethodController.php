<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentMethodController extends Controller
{
    public function index()
    {
        return view('payment_methods.index', [
            'paymentMethods' => PaymentMethod::where('user_id', Auth::id())->get(),
        ]);
    }

    public function addPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            // Store only a processor-issued reference (for example Stripe pm_*),
            // never a card number, CVV, or other raw payment credential.
            'details' => ['required', 'string', 'max:255', 'regex:/^(pm_|tok_|paypal_|vault_)/'],
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $paymentMethod = PaymentMethod::create($validator->validated() + ['user_id' => Auth::id()]);

        return response()->json($paymentMethod, 201);
    }

    public function editPaymentMethod(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'details' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^(pm_|tok_|paypal_|vault_)/'],
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $paymentMethod = PaymentMethod::where('user_id', Auth::id())->find($id);

        if (! $paymentMethod) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $paymentMethod->update($validator->validated());

        return response()->json($paymentMethod);
    }

    public function viewPaymentMethod($id)
    {
        $paymentMethod = PaymentMethod::where('user_id', Auth::id())->find($id);

        if (! $paymentMethod) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        return response()->json($paymentMethod);
    }

    public function deletePaymentMethod($id)
    {
        $paymentMethod = PaymentMethod::where('user_id', Auth::id())->find($id);

        if (! $paymentMethod) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted successfully.']);
    }

    public function setDefaultPaymentMethod($id)
    {
        $paymentMethod = PaymentMethod::where('user_id', Auth::id())->find($id);

        if (! $paymentMethod) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        DB::transaction(function () use ($paymentMethod) {
            PaymentMethod::where('user_id', Auth::id())->update(['is_default' => false]);
            $paymentMethod->update(['is_default' => true]);
        });

        return redirect()->route('payment_methods.index')->with('success', 'Default payment method updated.');
    }

    public function initiateTransaction(Request $request)
    {
        // This method would contain logic to initiate a transaction with a payment gateway.
        // Specific implementation details would depend on the payment gateway's API.
    }

    public function handleGatewayCallback(Request $request)
    {
        // This method would contain logic to handle callbacks or webhooks from a payment gateway.
        // Specific implementation details would depend on the payment gateway's API.
    }
}
