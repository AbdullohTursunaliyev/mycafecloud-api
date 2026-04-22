<?php

namespace App\Http\Resources\Booking;

use App\Http\Resources\BaseJsonResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BookingListResource extends BaseJsonResource
{
    /**
     * @var LengthAwarePaginator
     */
    public $resource;

    public function toArray($request): array
    {
        $payload = $this->resource->toArray();
        $payload['data'] = collect($this->resource->items())
            ->map(fn($booking) => (new BookingResource($booking))->toArray($request))
            ->values()
            ->all();

        return [
            'data' => $payload,
        ];
    }
}
