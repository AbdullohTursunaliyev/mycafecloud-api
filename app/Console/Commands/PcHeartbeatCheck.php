<?php

// app/Console/Commands/PcHeartbeatCheck.php
namespace App\Console\Commands;

use App\Models\Pc;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PcHeartbeatCheck extends Command
{
    protected $signature = 'pcs:heartbeat-check';
    protected $description = 'Mark PCs offline if heartbeat timeout exceeded';

    public function handle(): int
    {
        $timeoutSec = 30;
        $threshold = Carbon::now()->subSeconds($timeoutSec);

        // faqat online/busy/locked holatdagilarni tekshiramiz
        $affected = Pc::whereIn('status', ['online','busy','locked'])
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $threshold);
            })
            ->update(['status' => 'offline']);

        $this->info("PCs marked offline: {$affected}");
        return self::SUCCESS;
    }
}

