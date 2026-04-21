<?php

namespace App\Http\Middleware;

use App\Models\PcDeviceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PcDeviceAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $plain = trim(substr($auth, 7));
        if ($plain === '') return response()->json(['message' => 'Unauthorized'], 401);

        $hash = hash('sha256', $plain);

        $token = PcDeviceToken::where('token_hash', $hash)->first();
        if (!$token) return response()->json(['message' => 'Unauthorized'], 401);

        $token->update(['last_used_at' => now()]);

        // requestga pc/tenant ni “attach” qilamiz
        $request->attributes->set('pc_id', $token->pc_id);
        $request->attributes->set('tenant_id', $token->tenant_id);

        return $next($request);
    }
}

