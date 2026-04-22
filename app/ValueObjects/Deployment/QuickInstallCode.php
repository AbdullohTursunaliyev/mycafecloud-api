<?php

namespace App\ValueObjects\Deployment;

use Carbon\CarbonInterface;

readonly class QuickInstallCode
{
    public function __construct(
        public string $pairCode,
        public ?string $zone,
        public CarbonInterface $expiresAt,
    ) {
    }
}
