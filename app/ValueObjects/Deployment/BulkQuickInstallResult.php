<?php

namespace App\ValueObjects\Deployment;

readonly class BulkQuickInstallResult
{
    /**
     * @param list<QuickInstallCode> $codes
     */
    public function __construct(
        public int $count,
        public array $codes,
        public string $installerScriptUrlPattern,
        public string $installOneLinerPattern,
        public string $gpoScriptUrlPattern,
        public string $gpoOneLinerPattern,
    ) {
    }
}
