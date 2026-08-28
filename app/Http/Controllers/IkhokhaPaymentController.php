<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\OrderPaymentService;
use App\Services\Payments\IkhokhaGateway;
use App\Support\CustomerOrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class IkhokhaPaymentController extends Controller
{
    public function webhook(Request $request, IkhokhaGateway $gateway, OrderPaymentService $payments): JsonResponse
    {
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['message' => 'Payload too large'], 413);
        }
        $decoded = json_decode($request->getContent());
        if (! $decoded instanceof \stdClass) {
            return response()->json(['message' => 'Invalid JSON object'], 400);
        }
        $payload = $request->json()->all();
        unset($payload['text']);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! $gateway->verifyWebhook(
            '/'.ltrim($request->path(), '/'),
            $body,
            $request->header('ik-sign'),
            $request->header('ik-appid'),
        )) {
            Log::warning('Rejected iKhokha webhook with an invalid signature.');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $validator = Validator::make($payload, [
            'externalTransactionID' => 'required|string|max:255',
            'paylinkID' => 'required|string|max:128',
            'status' => 'required|string|in:SUCCESS,FAILURE',
            'responseCode' => 'required|string|max:32',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid payment payload'], 422);
        }

        $transaction = PaymentTransaction::query()
            ->where('gateway', 'ikhokha')
            ->where('external_transaction_id', $payload['externalTransactionID'] ?? '')
            ->first();

        if (! $transaction || ($payload['paylinkID'] ?? null) !== $transaction->gateway_reference) {
            return response()->json(['message' => 'Unknown transaction'], 404);
        }

        if (($payload['status'] ?? null) === 'SUCCESS' && ($payload['responseCode'] ?? null) === '00') {
            $payments->markPaid($transaction, $payload);
        } elseif (($payload['status'] ?? null) === 'FAILURE') {
            $payments->markFailed($transaction, $payload);
        } else {
            return response()->json(['message' => 'Unsupported payment status'], 422);
        }

        return response()->json(['received' => true]);
    }

    public function return(Request $request, Order $order): RedirectResponse
    {
        // A browser return hint is not proof of payment success or cancellation.
        $status = CustomerOrderStatus::describe($order);

        return redirect()->to($this->confirmationUrl($order))
            ->with($status['confirmed'] ? 'success' : 'warning', $status['message']);
    }

    private function confirmationUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'checkout.confirmation', now()->addDays(7), ['order' => $order->id]
        );
    }
}
