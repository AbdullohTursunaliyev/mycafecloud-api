<?php

namespace App\Http\Resources\Booking;

use App\Http\Resources\BaseJsonResource;
use App\Models\Booking;

class BookingResource extends BaseJsonResource
{
    /**
     * @var Booking
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'pc_id' => (int) $this->resource->pc_id,
            'client_id' => (int) $this->resource->client_id,
            'start_at' => optional($this->resource->start_at)->toIso8601String(),
            'end_at' => optional($this->resource->end_at)->toIso8601String(),
            'status' => (string) $this->resource->status,
            'note' => $this->resource->note,
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'pc' => $this->resource->pc ? [
                'id' => (int) $this->resource->pc->id,
                'code' => $this->resource->pc->code,
                'status' => $this->resource->pc->status,
            ] : null,
            'client' => $this->resource->client ? [
                'id' => (int) $this->resource->client->id,
                'account_id' => $this->resource->client->account_id,
                'login' => $this->resource->client->login,
                'phone' => $this->resource->client->phone,
            ] : null,
            'creator' => $this->resource->creator ? [
                'id' => (int) $this->resource->creator->id,
                'login' => $this->resource->creator->login,
                'name' => $this->resource->creator->name,
            ] : null,
        ];
    }
}
