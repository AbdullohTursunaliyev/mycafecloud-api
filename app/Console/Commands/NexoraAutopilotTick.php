<?php

namespace App\Console\Commands;

use App\Services\NexoraAssistantService;
use Illuminate\Console\Command;

class NexoraAutopilotTick extends Command
{
    protected $signature = 'nexora:autopilot-tick';
    protected $description = 'Run safe Nexora autopilot rules for tenants that enabled them';

    public function __construct(
        private readonly NexoraAssistantService $assistant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->assistant->runAutopilotTick();

        $this->info(sprintf(
            'nexora-autopilot processed=%d executed=%d',
            (int) ($result['processed_tenants'] ?? 0),
            (int) ($result['executed_commands'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
