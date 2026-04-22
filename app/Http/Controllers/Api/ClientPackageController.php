<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AttachClientPackageRequest;
use App\Services\ClientPackageService;

class ClientPackageController extends Controller
{
    public function __construct(
        private readonly ClientPackageService $packages,
    ) {
    }

    public function attach(AttachClientPackageRequest $request, int $id)
    {
        $operator = $request->user('operator');

        $result = $this->packages->attach(
            (int) $operator->tenant_id,
            $operator,
            $id,
            $request->packageId(),
            $request->paymentMethod(),
        );

        return response()->json([
            'data' => [
                'client' => $result['client'],
                'client_package_id' => $result['client_package']->id,
                'package' => $result['package'],
                'payment_method' => $result['payment_method'],
                'amount' => $result['amount'],
                'shift_id' => $result['shift_id'],
            ],
        ]);
    }
}
