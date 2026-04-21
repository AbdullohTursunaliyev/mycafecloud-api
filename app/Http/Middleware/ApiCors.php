<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->headers->get('Origin', '');
        $allowed = (array) config('cors.allowed_origins', []);
        $allowAll = in_array('*', $allowed, true);

        $allowOrigin = '';
        if ($origin !== '' && ($allowAll || in_array($origin, $allowed, true))) {
            $allowOrigin = $origin;
        }

        if ($request->getMethod() === 'OPTIONS') {
            $resp = response('', 204);
        } else {
            $resp = $next($request);
        }

        if ($allowOrigin !== '') {
            $resp->headers->set('Access-Control-Allow-Origin', $allowOrigin);
            $resp->headers->set('Vary', 'Origin', false);
        }

        $resp->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');

        $reqHeaders = (string) $request->headers->get('Access-Control-Request-Headers', '');
        if ($reqHeaders !== '') {
            $resp->headers->set('Access-Control-Allow-Headers', $reqHeaders);
        } else {
            $resp->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Requested-With');
        }

        $resp->headers->set('Access-Control-Max-Age', '86400');

        return $resp;
    }
}
