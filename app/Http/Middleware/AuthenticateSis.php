<?php

namespace App\Http\Middleware;

use App\Services\Library\SisLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The door for SIS (/api/sis/v1/*): the Bearer key FileNest issued, while the link is on. */
class AuthenticateSis
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SisLink::check((string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        SisLink::touch();

        return $next($request);
    }
}
