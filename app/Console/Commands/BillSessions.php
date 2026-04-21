<?php

namespace App\Console\Commands;

use App\Services\SessionBillingService;
use Illuminate\Console\Command;

class BillSessions extends Command
{
    protected $signature = 'sessions:bill';
    protected $description = 'Bill active sessions each minute (balance/package) and auto-finish when funds end';

    public function __construct(private readonly SessionBillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->billing->tick(now(), 500);
        $this->line(sprintf(
            'processed=%d billed=%d stopped=%d skipped=%d',
            $stats['processed'] ?? 0,
            $stats['billed_sessions'] ?? 0,
            $stats['stopped_sessions'] ?? 0,
            $stats['skipped'] ?? 0
        ));

        return self::SUCCESS;
    }
}
