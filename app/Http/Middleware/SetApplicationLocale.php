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
        // The account's language, else the one chosen on the sign-in page (session), else the site's.
        $locale = $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? Setting::get('default_locale', config('app.locale'));

        if (\App\Support\Locales::isActive($locale)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
