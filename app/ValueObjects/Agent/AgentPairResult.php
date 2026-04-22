<?php

namespace App\ValueObjects\Agent;

use Carbon\CarbonInterface;

readonly class AgentPairResult
{
    public function __construct(
        public int $pcId,
        public string $pcCode,
        public ?string $zone,
        public string $deviceToken,
        public ?CarbonInterface $deviceTokenExpiresAt,
        public int $pollIntervalSec,
        public bool $repairMode,
    ) {
    }
}
