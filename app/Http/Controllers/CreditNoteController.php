<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Refund;
use App\Support\RefundAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditNoteController extends Controller
{
    public function show(Request $request, CreditNote $creditNote): View
    {
        $order = $creditNote->invoice->order;
        $actor = $request->user();
        $staff = RefundAccess::view($actor) && Gate::forUser($actor)->allows('view', $order);
        abort_unless($request->hasValidSignature() || $staff || ($actor && $order->isAccessibleTo($actor)), 403);

        return view('credit-notes.show', compact('creditNote'));
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(RefundAccess::view($request->user()), 403);
        $data = Validator::make($request->query(), ['order' => 'nullable|integer|min:1'])->validate();
        $query = Refund::whereNotNull('version')->where('status', 'completed')->with('creditNote');
        if (filled($data['order'] ?? null)) {
            $order = Order::findOrFail($data['order']);
            Gate::authorize('view', $order);
            $query->where('order_id', $order->id);
        }
        abort_if((clone $query)->count() > 10000, 422, 'Narrow the export to one order; the limit is 10,000 records.');
        $rows = $query->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $safe = fn ($value) => preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : (string) $value;
            fputcsv($out, ['Refund', 'Order', 'Credit note', 'Recorded at (UTC)', 'External completion (UTC)', 'Currency', 'Gross refund', 'Tax adjustment', 'Gateway', 'External reference', 'Basis'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map($safe, [$row->id, $row->order_id, $row->creditNote?->number, $row->processed_at?->utc()->toIso8601String(),
                    $row->external_completed_at?->utc()->toIso8601String(), $row->currency, $row->amount, $row->tax_amount, $row->refund_method,
                    $row->transaction_id, 'Staff-recorded external refund']), ',', '"', '');
            }
            fclose($out);
        }, 'recorded-refunds-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
