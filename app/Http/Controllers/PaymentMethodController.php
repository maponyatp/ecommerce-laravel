<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PrivateCustomerDirectory;
use App\Models\PaymentMethod;
use App\Services\SavedPaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentMethodController extends Controller
{
    private const REFERENCE_RULE = 'regex:/\\A(?:pm_|tok_|paypal_|vault_)[A-Za-z0-9_-]{4,240}\\z/';

    public function __construct()
    {
        $this->middleware(['auth', PrivateCustomerDirectory::class]);
    }

    public function index()
    {
        return view('payment_methods.index', ['paymentMethods' => PaymentMethod::where('user_id', Auth::id())->orderByDesc('is_default')->orderBy('id')->get()]);
    }

    public function addPaymentMethod(Request $request)
    {
        abort_unless($request->isMethod('post'), 405);
        $data = $this->validateReference($request, [
            'name' => 'required|string|max:255',
            'details' => ['required', 'string', 'max:255', self::REFERENCE_RULE],
            'is_default' => 'sometimes|boolean',
        ]);
        $method = app(SavedPaymentMethodService::class)->save(Auth::id(), $data);

        return $request->expectsJson() ? response()->json($method, 201)
            : redirect()->route('payment_methods.index')->with('success', 'Reference saved. No payment was verified or charged.');
    }

    public function editPaymentMethod(Request $request, $id)
    {
        $method = PaymentMethod::where('user_id', Auth::id())->findOrFail($id);
        // The existing GET route is display-only, even with query parameters.
        if ($request->isMethod('get') || $request->isMethod('head')) {
            return view('payment_methods.edit', ['paymentMethod' => $method]);
        }
        abort_unless($request->isMethod('post'), 405);
        $data = $this->validateReference($request, [
            'name' => 'sometimes|required|string|max:255',
            'details' => ['sometimes', 'nullable', 'string', 'max:255', self::REFERENCE_RULE],
            'is_default' => 'sometimes|boolean',
        ]);
        if (blank($data['details'] ?? null)) {
            unset($data['details']);
        }
        $method = app(SavedPaymentMethodService::class)->save(Auth::id(), $data, (int) $id);

        return $request->expectsJson() ? response()->json($method)
            : redirect()->route('payment_methods.index')->with('success', 'Saved payment reference updated.');
    }

    public function viewPaymentMethod($id)
    {
        return response()->json(PaymentMethod::where('user_id', Auth::id())->findOrFail($id));
    }

    private function validateReference(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails() && ! $request->expectsJson()) {
            // Invalid input could contain card data. Never flash it into a session.
            throw new ValidationException($validator, redirect()->back()->withErrors($validator)
                ->withInput($request->only('name', 'is_default')));
        }

        return $validator->validate();
    }

    public function deletePaymentMethod(Request $request, $id)
    {
        abort_unless($request->isMethod('delete'), 405);
        app(SavedPaymentMethodService::class)->delete(Auth::id(), (int) $id);

        return $request->expectsJson() ? response()->json(['message' => 'Payment method deleted successfully.'])
            : redirect()->route('payment_methods.index')->with('success', 'Saved reference removed.');
    }

    public function setDefaultPaymentMethod(Request $request, $id)
    {
        abort_unless($request->isMethod('post'), 405);
        app(SavedPaymentMethodService::class)->save(Auth::id(), ['is_default' => true], (int) $id);

        return $request->expectsJson() ? response()->json(['message' => 'Default payment method updated.'])
            : redirect()->route('payment_methods.index')->with('success', 'Default payment method updated.');
    }

    public function initiateTransaction(Request $request)
    {
        return response()->json(['success' => false, 'error' => 'Use the supported order checkout flow.'], 409);
    }

    public function handleGatewayCallback(Request $request)
    {
        return response()->json(['success' => false, 'error' => 'This legacy callback is not supported.'], 410);
    }
}
