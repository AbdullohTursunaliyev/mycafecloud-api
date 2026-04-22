<?php

namespace App\ValueObjects\ClientAuth;

readonly class ClientShellLoginResult
{
    public function __construct(
        public string $token,
        public array $client,
        public array $pc,
        public array $session,
        public ?string $note,
    ) {
    }
}
