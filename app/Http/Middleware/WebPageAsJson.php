<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the apps use a website page's own controller through the token API (2026-11-10):
 * the page's Inertia reply comes back as its props, and a "back with a message" reply as
 * {"message": …} (or 422 with the errors it carried). The rules, checks and activity log
 * stay in one place for the website and the apps.
 */
class WebPageAsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        // Inertia answers a request carrying this header with the page as JSON.
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->headers->has('X-Inertia')) {
            $page = $response->getData(true);
            if (is_array($page) && array_key_exists('props', $page)) {
                $out = new JsonResponse($page['props'] ?? [], $response->getStatusCode());
                $out->headers->remove('X-Inertia');

                return $out;
            }
        }

        if ($response instanceof RedirectResponse) {
            $session = $response->getSession() ?? app('session.store');
            $errors = $session->get('errors');
            $message = $session->get('success') ?? $session->get('error') ?? $session->get('warning');
            $session->forget(['errors', 'success', 'error', 'warning', '_flash.new', '_flash.old']);

            if ($errors instanceof ViewErrorBag && $errors->any()) {
                $bag = $errors->getBag('default');

                return new JsonResponse(['message' => $bag->first(), 'errors' => $bag->toArray()], 422);
            }

            return new JsonResponse(['message' => $message ?? 'تم.']);
        }

        return $response;
    }
}
