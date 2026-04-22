<?php

namespace App\Http\Controllers\Api;

use App\Actions\Booking\CancelBookingAction;
use App\Actions\Booking\CreateBookingAction;
use App\Actions\Booking\ListBookingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CreateBookingRequest;
use App\Http\Resources\Booking\BookingListResource;
use App\Http\Resources\Booking\BookingResource;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly ListBookingsAction $listBookings,
        private readonly CreateBookingAction $createBooking,
        private readonly CancelBookingAction $cancelBooking,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = (int)$request->user()->tenant_id;

        return new BookingListResource($this->listBookings->execute($tenantId, [
            'status' => $request->input('status'),
            'pc_id' => $request->input('pc_id'),
            'client_id' => $request->input('client_id'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'per_page' => $request->input('per_page', 20),
        ]));
    }

    public function store(CreateBookingRequest $request)
    {
        $tenantId = (int)$request->user()->tenant_id;
        $operatorId = (int)$request->user()->id;

        $booking = $this->createBooking->execute($tenantId, $operatorId, $request->payload());

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Request $request, int $id)
    {
        $tenantId = (int)$request->user()->tenant_id;

        $this->cancelBooking->execute($tenantId, $id);

        return response()->json(['ok' => true]);
    }
}
