<?php

namespace App\Http\Middleware;

use App\Services\ClientTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientAuth
{
    public function __construct(private readonly ClientTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $plain = trim(substr($auth, 7));
        if ($plain === '') return response()->json(['message' => 'Unauthorized'], 401);

        $token = $this->tokens->resolve($plain, touch: true);
        if (!$token) return response()->json(['message' => 'Unauthorized'], 401);

        $request->attributes->set('tenant_id', $token->tenant_id);
        $request->attributes->set('client_id', $token->client_id);

        return $next($request);
    }
}
