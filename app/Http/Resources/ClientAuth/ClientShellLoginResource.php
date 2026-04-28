<?php

namespace App\Http\Resources\ClientAuth;

use App\Http\Resources\BaseJsonResource;
use App\ValueObjects\ClientAuth\ClientShellLoginResult;

class ClientShellLoginResource extends BaseJsonResource
{
    /**
     * @var ClientShellLoginResult
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'token' => $this->resource->token,
            'client' => $this->resource->client,
            'pc' => $this->resource->pc,
            'session' => $this->resource->session,
            'billing_options' => $this->resource->billingOptions,
            'note' => $this->resource->note,
        ];
    }
}
