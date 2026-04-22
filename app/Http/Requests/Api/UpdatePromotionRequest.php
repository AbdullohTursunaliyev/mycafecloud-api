<?php

namespace App\Http\Requests\Api;

class UpdatePromotionRequest extends PromotionPayloadRequest
{
    protected function isPartial(): bool
    {
        return true;
    }
}
