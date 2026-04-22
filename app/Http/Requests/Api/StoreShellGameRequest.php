<?php

namespace App\Http\Requests\Api;

class StoreShellGameRequest extends ShellGamePayloadRequest
{
    protected function isPartial(): bool
    {
        return false;
    }
}
