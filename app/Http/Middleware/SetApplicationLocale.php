<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // The system is in Arabic only (2026-11-09, by request): everyone sees Arabic, whatever
        // language their account was saved with. Exams keep their own languages for students.
        App::setLocale('ar');

        return $next($request);
    }
}
