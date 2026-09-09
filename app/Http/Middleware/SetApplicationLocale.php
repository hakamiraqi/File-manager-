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
        $locale = $request->user()?->locale
            ?? Setting::get('default_locale', config('app.locale'));

        if (array_key_exists($locale, config('filenest.supported_locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
