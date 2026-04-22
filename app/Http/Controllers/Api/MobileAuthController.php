<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobilePayloadResource;
use App\Services\MobileAuthService;
use Illuminate\Http\Request;

class MobileAuthController extends Controller
{
    public function __construct(
        private readonly MobileAuthService $mobileAuth,
    ) {
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9_\\-.]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
        ]);

        return (new MobilePayloadResource($this->mobileAuth->register($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        return new MobilePayloadResource($this->mobileAuth->login($data));
    }

    public function me(Request $request)
    {
        return new MobilePayloadResource(
            $this->mobileAuth->me((int) $request->attributes->get('mobile_user_id'))
        );
    }

    public function profile(Request $request)
    {
        return new MobilePayloadResource(
            $this->mobileAuth->profile((int) $request->attributes->get('mobile_user_id'))
        );
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['nullable', 'string', 'max:64'],
            'avatar_url' => ['nullable', 'string', 'max:2000'],
        ]);

        return new MobilePayloadResource(
            $this->mobileAuth->saveProfile(
                (int) $request->attributes->get('mobile_user_id'),
                $data,
            )
        );
    }

    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:5120'],
        ]);

        return new MobilePayloadResource(
            $this->mobileAuth->uploadAvatar(
                (int) $request->attributes->get('mobile_user_id'),
                $request->file('avatar'),
            )
        );
    }

    public function switchClub(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);

        $response = $this->mobileAuth->switchClub(
            (string) $request->attributes->get('mobile_login'),
            (int) $data['tenant_id'],
        );

        if (isset($response['status_code'])) {
            $statusCode = (int) $response['status_code'];
            unset($response['status_code']);

            return response()->json($response, $statusCode);
        }

        return new MobilePayloadResource($response);
    }

    public function logout(Request $request)
    {
        $auth = (string) $request->header('Authorization', '');
        $plain = trim(substr($auth, 7));
        $this->mobileAuth->logout($plain);

        return response()->json(['ok' => true]);
    }
}
