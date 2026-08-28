<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerDirectoryService
{
    public const KINDS = ['all' => 'All purchase contacts', 'account' => 'Registered accounts',
        'legacy' => 'Legacy customer records', 'guest' => 'Guest receipt contacts', 'unknown' => 'Unidentified orders'];

    private const KIND_SQL = "CASE WHEN user_id IS NOT NULL THEN 'account' WHEN customer_id IS NOT NULL THEN 'legacy' WHEN customer_email IS NOT NULL AND TRIM(customer_email) <> '' THEN 'guest' ELSE 'unknown' END";

    private const TEAM_SQL = "COALESCE(CAST(team_id AS CHAR), 'none')";

    private const IDENTITY_SQL = "CASE WHEN user_id IS NOT NULL THEN HEX(CAST(user_id AS CHAR)) WHEN customer_id IS NOT NULL THEN HEX(CAST(customer_id AS CHAR)) WHEN customer_email IS NOT NULL AND TRIM(customer_email) <> '' THEN HEX(customer_email) ELSE HEX(CAST(id AS CHAR)) END";

    public function profiles(string $search = '', string $kind = 'all', string $label = '')
    {
        // Group existing order links, never infer ownership from an account's current email.
        $groups = Order::query()->selectRaw('MIN(id) AS profile_id, MIN(user_id) AS account_id, MIN(customer_id) AS legacy_id, MIN(customer_email) AS receipt_email, COUNT(*) AS order_count, MAX(created_at) AS last_order_at')
            ->selectRaw(self::KIND_SQL.' AS contact_kind')->selectRaw(self::TEAM_SQL.' AS directory_team_key')
            ->selectRaw(self::IDENTITY_SQL.' AS directory_identity_hex')
            ->groupByRaw(self::TEAM_SQL)->groupByRaw(self::KIND_SQL)->groupByRaw(self::IDENTITY_SQL);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        if ($search !== '') {
            // Filter groups, not their orders, so purchase counts stay complete when an email changed.
            $groups->selectRaw("MAX(CASE WHEN customer_email LIKE ? ESCAPE '!' THEN 1 ELSE 0 END) AS email_matches", [$pattern]);
        }

        return DB::query()->fromSub($groups, 'purchase_contacts')
            ->leftJoin('customer_profiles as staff_profile', function ($join) {
                $join->on('staff_profile.directory_team_key', '=', 'purchase_contacts.directory_team_key')
                    ->on('staff_profile.directory_kind', '=', 'purchase_contacts.contact_kind')
                    ->whereRaw('HEX(staff_profile.directory_identity) = purchase_contacts.directory_identity_hex');
            })
            ->select(['purchase_contacts.*', 'staff_profile.preferred_name', 'staff_profile.labels as staff_labels'])
            ->when($kind !== 'all', fn ($query) => $query->where('contact_kind', $kind))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('email_matches', 1)
                ->orWhereRaw("staff_profile.preferred_name LIKE ? ESCAPE '!'", [$pattern])))
            ->when($label !== '', fn ($query) => $query->whereJsonContains('staff_profile.labels', mb_strtolower(trim($label))))
            ->orderByDesc('last_order_at')->orderByDesc('profile_id');
    }

    public function ordersFor(Order $anchor): Builder
    {
        $orders = Order::query()->where('team_id', $anchor->team_id);
        if ($anchor->user_id !== null) {
            return $orders->where('user_id', $anchor->user_id);
        }
        $orders->whereNull('user_id');
        if ($anchor->customer_id !== null) {
            return $orders->where('customer_id', $anchor->customer_id);
        }
        $orders->whereNull('customer_id');
        if ($anchor->customer_email !== null && trim($anchor->customer_email, ' ') !== '') {
            return $orders->whereRaw('HEX(customer_email) = ?', [strtoupper(bin2hex($anchor->customer_email))]);
        }

        return $orders->whereKey($anchor->id);
    }

    public function kind(Order $anchor): string
    {
        return $anchor->user_id !== null ? 'account' : ($anchor->customer_id !== null ? 'legacy'
            : ($anchor->customer_email !== null && trim($anchor->customer_email, ' ') !== '' ? 'guest' : 'unknown'));
    }

    public function paidTotals(Order $anchor)
    {
        return $this->ordersFor($anchor)->where('payment_status', 'paid')
            ->selectRaw('currency, COUNT(*) AS order_count, SUM(total_amount) AS paid_total')
            ->groupBy('currency')->orderBy('currency')->get();
    }
}
