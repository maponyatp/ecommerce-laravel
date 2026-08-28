<?php

namespace App\Console\Commands;

use App\Services\OrderReceiptService;
use App\Services\StockReservationService;
use Illuminate\Console\Command;

class RecoverCheckouts extends Command
{
    protected $signature = 'commerce:recover-checkouts {--limit=100 : Maximum records per category}';

    protected $description = 'Release expired stock holds and retry due order receipts without changing gateway payment state.';

    public function handle(StockReservationService $stock, OrderReceiptService $receipts): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $expired = $stock->expire($limit);
        $retried = $receipts->recover($limit);
        $this->info("Released {$expired} expired holds; checked {$retried} due receipts.");

        return self::SUCCESS;
    }
}
