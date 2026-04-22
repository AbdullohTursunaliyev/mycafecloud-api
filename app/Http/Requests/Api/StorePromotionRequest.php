<?php

namespace App\Http\Requests\Api;

class StorePromotionRequest extends PromotionPayloadRequest
{
    protected function isPartial(): bool
    {
        return false;
    }
}
