<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\OperatorLoginRequest;
use App\Http\Resources\Auth\OperatorLoginResource;
use App\Http\Resources\Auth\OperatorMeResource;
use App\Services\OperatorAuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly OperatorAuthService $auth,
    ) {
    }

    public function login(OperatorLoginRequest $request)
    {
        return response()->json(
            new OperatorLoginResource(
                $this->auth->loginApi(
                    $request->licenseKey(),
                    $request->loginValue(),
                    $request->passwordValue(),
                )
            )
        );
    }

    public function me(Request $request)
    {
        return response()->json(
            new OperatorMeResource($request->user('operator') ?: $request->user())
        );
    }

    public function logout(Request $request)
    {
        ($request->user('operator') ?: $request->user())?->tokens()->delete();

        return response()->json(['ok' => true]);
    }
}
