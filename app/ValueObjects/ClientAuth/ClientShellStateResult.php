<?php

namespace App\ValueObjects\ClientAuth;

readonly class ClientShellStateResult
{
    public function __construct(
        public bool $locked,
        public array $client,
        public array $pc,
        public array $session,
        public ?array $command,
    ) {
    }
}
