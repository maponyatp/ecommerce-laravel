<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\DeliveryBooking;
use App\Models\DeliverySlot;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\RefundChange;
use App\Models\User;
use App\Support\RefundAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RefundManagementService
{
    public const STATUSES = ['pending' => 'Awaiting external refund', 'completed' => 'External refund recorded', 'cancelled' => 'Request cancelled'];

    public static function minor(mixed $amount): int
    {
        $value = (string) $amount;
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a non-negative amount with at most two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    public function balances(Order $order): array
    {
        $rows = Refund::where('order_id', $order->id)->whereNotNull('version')->get();
        $active = $rows->whereIn('status', ['pending', 'completed']);
        $completed = $rows->where('status', 'completed');
        $gross = self::minor($order->total_amount);
        $tax = self::minor($order->invoice?->document_snapshot['totals']['tax'] ?? '0');
        $used = $active->sum(fn ($r) => self::minor($r->amount));
        $usedTax = $active->sum(fn ($r) => self::minor($r->tax_amount));

        return ['paid' => $gross, 'recorded' => $completed->sum(fn ($r) => self::minor($r->amount)),
            'pending' => $rows->where('status', 'pending')->sum(fn ($r) => self::minor($r->amount)),
            'remaining' => max(0, $gross - $used), 'remaining_tax' => max(0, $tax - $usedTax),
            'remaining_net' => max(0, $gross - $tax - ($used - $usedTax))];
    }

    public function request(Order $order, array $input, User $actor): Refund
    {
        $this->authorize($order, $actor);
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/D'],
            'tax_amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/D'], 'reason' => 'required|string|min:5|max:255'])->validate();
        $amount = self::minor($data['amount']);
        $tax = self::minor($data['tax_amount']);
        if ($amount < 1 || $tax > $amount) {
            $this->invalid('Enter a positive refund amount, with tax no greater than the refund.');
        }
        $data['reason'] = trim($data['reason']);
        if (mb_strlen($data['reason']) < 5) {
            $this->invalid('Give a meaningful customer-facing reason.', 'reason');
        }
        $hash = hash('sha256', json_encode([$order->id, $amount, $tax, $data['reason']], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($order, $data, $amount, $tax, $actor, $hash) {
                $order = Order::lockForUpdate()->findOrFail($order->id);
                $this->authorize($order, $actor);
                $existing = Refund::where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if ($existing->order_id !== $order->id || $existing->request_hash !== $hash) {
                        $this->invalid('This request key was already used. Reload before creating a different refund.');
                    }

                    return $existing;
                }
                [$invoice, $payment] = $this->eligible($order);
                $balance = $this->balances($order);
                if ($amount > $balance['remaining'] || $tax > $balance['remaining_tax'] || $amount - $tax > $balance['remaining_net']) {
                    $this->invalid('The amount or tax exceeds the unallocated invoice balance. Include other pending refunds and reload.');
                }
                $refund = new Refund(['order_id' => $order->id, 'amount' => self::decimal($amount), 'reason' => $data['reason'],
                    'status' => 'pending', 'refund_method' => $payment->gateway, 'restock_items' => false]);
                $refund->forceFill(['invoice_id' => $invoice->id, 'payment_transaction_id' => $payment->id, 'request_key' => $data['request_key'],
                    'request_hash' => $hash, 'currency' => 'ZAR', 'tax_amount' => self::decimal($tax), 'requested_by' => $actor->id, 'version' => 1])->save();
                $order->increment('fulfillment_version');
                $this->audit($refund, 'requested', $actor, 'Request created. No money moved; fulfilment is paused while this request is pending.');

                return $refund->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->invalid('This request was already recorded. Reload the refund list before retrying.');
        }
    }

    public function cancel(Refund $refund, int $version, string $note, User $actor): Refund
    {
        $this->authorize($refund->order, $actor);
        Validator::make(['note' => trim($note)], ['note' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($refund, $version, $note, $actor) {
            $order = Order::lockForUpdate()->findOrFail($refund->order_id);
            $this->authorize($order, $actor);
            $refund = Refund::lockForUpdate()->findOrFail($refund->id);
            $this->pending($refund, $version);
            $refund->forceFill(['status' => 'cancelled', 'version' => $refund->version + 1])->save();
            $order->increment('fulfillment_version');
            $this->audit($refund, 'request_cancelled', $actor, trim($note));

            return $refund->fresh();
        }, 3);
    }

    public function recordExternal(Refund $refund, array $input, User $actor): Refund
    {
        $this->authorize($refund->order, $actor);
        $data = Validator::make($input, ['version' => 'required|integer|min:1',
            'external_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]{2,119}$/D'],
            'completed_at' => 'required|date|before_or_equal:now', 'evidence_note' => 'required|string|min:10|max:2000',
            'confirmed' => 'accepted'])->validate();
        $reference = strtoupper($data['external_reference']);
        $completedAt = CarbonImmutable::parse($data['completed_at'])->utc();
        $evidence = trim($data['evidence_note']);
        if (mb_strlen($evidence) < 10) {
            $this->invalid('Describe the evidence checked, without card or bank account details.', 'evidence_note');
        }
        try {
            return DB::transaction(function () use ($refund, $data, $reference, $completedAt, $evidence, $actor) {
                $order = Order::lockForUpdate()->findOrFail($refund->order_id);
                $this->authorize($order, $actor);
                $refund = Refund::lockForUpdate()->findOrFail($refund->id);
                if ($refund->status === 'completed' && $refund->confirmation_basis === 'staff_recorded_external'
                    && $refund->transaction_id === $reference && $refund->external_completed_at->equalTo($completedAt) && $refund->notes === $evidence) {
                    return $refund;
                }
                $this->pending($refund, (int) $data['version']);
                [$invoice, $payment] = $this->eligible($order);
                if ($invoice->id !== $refund->invoice_id || $payment->id !== $refund->payment_transaction_id
                    || $payment->gateway !== $refund->refund_method || $completedAt->lt($payment->paid_at)) {
                    $this->invalid('Payment/invoice details changed, or completion predates the payment. Review before recording.');
                }
                $referenceHash = hash('sha256', $payment->gateway.':'.$reference);
                if (in_array($reference, [strtoupper($payment->gateway_reference), strtoupper($payment->external_transaction_id ?? '')], true)) {
                    $this->invalid('Use the refund reference, not the original payment reference.', 'external_reference');
                }
                if (Refund::where('provider_reference_hash', $referenceHash)->exists()) {
                    $this->invalid('That refund reference is already recorded. Do not refund again.', 'external_reference');
                }
                $balance = $this->balances($order);
                $total = $balance['recorded'] + self::minor($refund->amount);
                if ($total > self::minor($order->total_amount)) {
                    $this->invalid('Recorded refunds would exceed the paid order.');
                }
                $refund->forceFill(['status' => 'completed', 'transaction_id' => $reference, 'provider_reference_hash' => $referenceHash,
                    'external_completed_at' => $completedAt, 'notes' => $evidence, 'processed_by' => $actor->id, 'processed_at' => now(),
                    'confirmation_basis' => 'staff_recorded_external', 'version' => $refund->version + 1])->save();
                $full = $total === self::minor($order->total_amount);
                $order->forceFill(['refund_total' => self::decimal($total), 'fully_refunded' => $full, 'partially_refunded' => ! $full,
                    'fulfillment_version' => $order->fulfillment_version + 1,
                    'payment_status' => $full ? 'refunded' : $order->payment_status, 'status' => $full ? 'refunded' : $order->status])->save();
                if ($full) {
                    $invoice->update(['payment_status' => 'refunded']);
                    // Release only local unshipped capacity, never pretend to cancel a courier booking.
                    if (in_array($order->shipping_status, ['unfulfilled', 'processing'], true) && $order->delivery_slot_id) {
                        DeliverySlot::whereKey($order->delivery_slot_id)->lockForUpdate()->first();
                        DeliveryBooking::where('order_id', $order->id)->whereIn('status', ['held', 'confirmed'])->update(['status' => 'released']);
                    }
                }
                $this->credit($refund, $invoice);
                $this->audit($refund, 'external_refund_recorded', $actor, $evidence);

                return $refund->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->invalid('The refund reference or credit note already exists. Reload before retrying.', 'external_reference');
        }
    }

    private function eligible(Order $order): array
    {
        if ($order->payment_status !== 'paid' || ! $order->payment_processed_at || $order->currency !== 'ZAR'
            || self::minor($order->total_amount) < 1 || ! $order->items()->exists()
            || $order->is_dropshipped || $order->items()->where(fn ($items) => $items->where('is_downloadable_snapshot', true)->orWhereNull('is_downloadable_snapshot'))->exists()) {
            $this->invalid('This workflow requires a settled, positive-value ZAR physical order. Digital, dropshipped, free and unverified orders need separate review.');
        }
        if (Refund::where('order_id', $order->id)->whereNull('version')->exists()) {
            $this->invalid('Legacy refund records need reconciliation before using this workflow.');
        }
        $completed = Refund::where('order_id', $order->id)->where('status', 'completed')->get()->sum(fn ($r) => self::minor($r->amount));
        if ($completed !== self::minor($order->refund_total ?? '0')) {
            $this->invalid('The refund ledger does not match the order. Resolve this discrepancy before continuing.');
        }
        $invoice = Invoice::where('order_id', $order->id)->lockForUpdate()->first();
        $document = $invoice?->document_snapshot;
        if (! $document || ($document['version'] ?? 0) < 2 || ($document['currency'] ?? null) !== 'ZAR'
            || self::minor($document['totals']['total'] ?? '0') !== self::minor($order->total_amount)
            || app(InvoiceDocumentService::class)->taxPresentation($document)['issues'] !== []) {
            $this->invalid('An intact invoice with confirmed seller/tax details is required. Legacy or incomplete documents need accounting review.');
        }
        $payments = PaymentTransaction::where('order_id', $order->id)->where('status', 'paid')->lockForUpdate()->get();
        $payment = $payments->first();
        if ($payments->count() !== 1 || ! $payment->paid_at || blank($payment->gateway_reference)
            || $payment->gateway !== $order->payment_method || $payment->currency !== 'ZAR'
            || self::minor($payment->amount) !== self::minor($order->total_amount)) {
            $this->invalid('Exactly one matching verified payment is required. Missing or multiple captures need payment reconciliation.');
        }

        return [$invoice, $payment];
    }

    private function credit(Refund $refund, Invoice $invoice): CreditNote
    {
        $original = $invoice->document_snapshot;
        $number = 'CN-'.now()->format('Y').'-'.str_pad((string) $refund->id, 6, '0', STR_PAD_LEFT);

        return CreditNote::create(['refund_id' => $refund->id, 'invoice_id' => $invoice->id, 'number' => $number, 'issued_at' => now(),
            'document_snapshot' => ['version' => 1, 'number' => $number, 'issued_at' => now()->toIso8601String(),
                'invoice_number' => $original['number'], 'invoice_issued_at' => $original['issued_at'], 'order_id' => $refund->order_id,
                'seller' => $original['seller'], 'buyer' => $original['buyer'] ?? null, 'branding' => $original['branding'] ?? null,
                'vat_status' => $original['vat_status'], 'currency' => 'ZAR', 'reason' => $refund->reason,
                'original_total' => $original['totals']['total'], 'totals' => ['net' => self::decimal(self::minor($refund->amount) - self::minor($refund->tax_amount)),
                    'tax' => $refund->tax_amount, 'total' => $refund->amount], 'confirmation_basis' => 'staff_recorded_external']]);
    }

    private function pending(Refund $refund, int $version): void
    {
        if ($refund->version === null || $refund->status !== 'pending' || $refund->version !== $version) {
            $this->invalid('This request changed or is no longer pending. Reload before recording or cancelling.', 'version');
        }
    }

    private function audit(Refund $refund, string $event, User $actor, string $note): void
    {
        RefundChange::create(['refund_id' => $refund->id, 'version' => $refund->version, 'event' => $event, 'actor_id' => $actor->id,
            'note' => $note, 'data' => ['status' => $refund->status, 'amount' => $refund->amount, 'tax_amount' => $refund->tax_amount,
                'currency' => $refund->currency, 'confirmation_basis' => $refund->confirmation_basis, 'external_reference' => $refund->transaction_id],
            'created_at' => now()]);
    }

    private function authorize(Order $order, User $actor): void
    {
        abort_unless(RefundAccess::manage($actor, $order), 403);
    }

    private function invalid(string $message, string $field = 'amount'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
