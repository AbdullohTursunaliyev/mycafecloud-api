<?php

namespace App\Http\Controllers\Cp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cp\CpLoginRequest;
use App\Http\Resources\Cp\CpLoginResource;
use App\Http\Resources\Cp\CpMeResource;
use App\Services\OperatorAuthService;
use Illuminate\Http\Request;

class CpAuthController extends Controller
{
    public function __construct(
        private readonly OperatorAuthService $auth,
    ) {
    }

    public function login(CpLoginRequest $request)
    {
        return response()->json(
            new CpLoginResource(
                $this->auth->loginCp(
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
            new CpMeResource(
                $this->auth->cpMe($request->user('operator') ?: $request->user())
            )
        );
    }

    public function logout(Request $request)
    {
        ($request->user('operator') ?: $request->user())?->tokens()->delete();

        return response()->json(['ok' => true]);
    }
}
