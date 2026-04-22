<?php

namespace App\Http\Middleware;

use App\Services\PcDeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PcDeviceAuth
{
    public function __construct(
        private readonly PcDeviceTokenService $tokens,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $plain = trim(substr($auth, 7));
        if ($plain === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = $this->tokens->resolve($plain, touch: true);
        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // requestga pc/tenant ni “attach” qilamiz
        $request->attributes->set('pc_id', $token->pc_id);
        $request->attributes->set('tenant_id', $token->tenant_id);
        $request->attributes->set('pc_device_token', $token);

        $rotation = null;
        if ($this->tokens->shouldRotate($token)) {
            $rotation = $this->tokens->rotate($token);
            $request->attributes->set('rotated_device_token', $rotation);
        }

        $response = $next($request);

        if ($rotation !== null) {
            $response->headers->set('X-Device-Token-Rotate', (string) $rotation['plain']);
        }

        return $response;
    }
}
