<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OwnerMobileLoginRequest;
use App\Http\Resources\OwnerMobile\OwnerMobileLoginResource;
use App\Http\Resources\OwnerMobile\OwnerMobileMeResource;
use App\Services\OwnerMobileAuthService;
use Illuminate\Http\Request;

class OwnerMobileAuthController extends Controller
{
    public function __construct(
        private readonly OwnerMobileAuthService $auth,
    ) {
    }

    public function login(OwnerMobileLoginRequest $request)
    {
        $payload = $this->auth->login(
            $request->licenseKey(),
            $request->loginValue(),
            $request->passwordValue(),
        );

        return response()->json(
            (new OwnerMobileLoginResource($payload))->resolve()
        );
    }

    public function me(Request $request)
    {
        return response()->json(
            (new OwnerMobileMeResource($request->user()))->resolve()
        );
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['ok' => true]);
    }
}
