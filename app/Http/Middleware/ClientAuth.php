<?php

namespace App\Http\Middleware;

use App\Models\ClientToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientAuth
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

        $token = ClientToken::where('token_hash', $hash)->first();
        if (!$token) return response()->json(['message' => 'Unauthorized'], 401);

        if ($token->expires_at && $token->expires_at->isPast()) {
            return response()->json(['message' => 'Token expired'], 401);
        }

        $token->update(['last_used_at' => now()]);

        $request->attributes->set('tenant_id', $token->tenant_id);
        $request->attributes->set('client_id', $token->client_id);

        return $next($request);
    }
}

