<?php

namespace App\ValueObjects\Deployment;

use Carbon\CarbonInterface;

readonly class QuickInstallResult
{
    public function __construct(
        public string $pairCode,
        public ?string $zone,
        public ?int $pcId,
        public CarbonInterface $expiresAt,
        public string $pairEndpoint,
        public string $installerScriptUrl,
        public string $installOneLiner,
        public string $gpoScriptUrl,
        public string $gpoOneLiner,
        public string $installerScript,
        public string $quickTestCurl,
        public string $powershellExample,
    ) {
    }
}
