<?php

namespace App\Http\Middleware;

use App\Models\IpRule;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IpAccessControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = Setting::get('ip_filter_mode', 'none');

        if ($mode === 'none') {
            return $next($request);
        }

        $ip = $request->ip();
        $rules = Cache::remember('ip_rules', 300, fn () => IpRule::where('type', $mode)->get());
        $matched = $rules->contains(fn (IpRule $rule) => $rule->matches($ip));

        $blocked = $mode === 'blacklist' ? $matched : ($mode === 'whitelist' && ! $matched);

        if ($blocked) {
            $redirect = Setting::get('ip_redirect_url', '');

            if ($redirect) {
                return redirect()->away($redirect);
            }

            abort(403, 'تم رفض الوصول من عنوان IP الخاص بك.');
        }

        return $next($request);
    }
}
