<?php

namespace App\Console\Commands;

use App\Services\SessionBillingService;
use Illuminate\Console\Command;

class SessionBillingTick extends Command
{
    protected $signature = 'billing:sessions-tick';
    protected $description = 'Deduct balance/package each minute for active sessions and lock PC when ended';

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
