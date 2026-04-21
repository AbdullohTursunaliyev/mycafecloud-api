<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\MobileToken;

class MobileAuth
{
    public function handle(Request $request, Closure $next)
    {
        $auth = (string) $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $plain = trim(substr($auth, 7));
        if ($plain === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $hash = hash('sha256', $plain);

        $token = MobileToken::query()
            ->where('token_hash', $hash)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token->update(['last_used_at' => now()]);

        $user = $token->user; // relation
        $request->attributes->set('mobile_user_id', $user->id);
        $request->attributes->set('mobile_login', $user->login);

        return $next($request);
    }
}
