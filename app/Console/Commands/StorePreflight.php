<?php

namespace App\Console\Commands;

use App\Services\StoreReadinessService;
use Illuminate\Console\Command;

class StorePreflight extends Command
{
    protected $signature = 'commerce:preflight {--json : Output the checks without secrets or customer data}';

    protected $description = 'Read-only store configuration checks; not a production-readiness certification';

    public function handle(StoreReadinessService $service): int
    {
        $report = $service->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Result'], array_map(fn ($check) => [$check['label'], $check['passed'] ? 'Configured' : 'Action required'], $report['checks']));
            $this->warn('Configuration checks are not launch approval. Manual acceptance evidence is still required.');
            foreach ($report['manual_reviews'] as $review) {
                $this->line('- '.$review);
            }
        }

        return $report['blocked_count'] ? self::FAILURE : self::SUCCESS;
    }
}
