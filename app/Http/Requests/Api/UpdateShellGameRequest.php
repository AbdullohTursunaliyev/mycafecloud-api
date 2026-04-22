<?php

namespace App\Http\Requests\Api;

class UpdateShellGameRequest extends ShellGamePayloadRequest
{
    protected function isPartial(): bool
    {
        return true;
    }
}
