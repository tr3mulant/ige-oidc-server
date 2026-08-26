<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapted from `tools.*` rather than copied. Two of its allowances are not needed
 * here and both were widening the policy for no reason:
 *
 * - `'unsafe-eval'` in `script-src`, which existed for Alpine's `new Function()`
 *   evaluation of `x-data` / `x-on`. This application ships no JavaScript framework;
 *   `resources/js/app.js` is empty and the auth screens are plain forms.
 * - `challenges.cloudflare.com` in three directives, for a Turnstile widget that
 *   guarded a registration form. There is no registration form here and never will be.
 *
 * `frame-ancestors 'none'` rather than `'self'`: nothing should embed a login page,
 * including this application. `form-action 'self'` matters more here than in an
 * ordinary app — it is what stops a credential form being repointed at another origin.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    protected function contentSecurityPolicy(): string
    {
        /**
         * In local development Vite serves assets from its own origin, recorded in
         * `public/hot` while the dev server runs. A different port is a different
         * origin, so `'self'` does not cover it and every asset plus the HMR socket is
         * blocked. The file never exists in production, so this adds nothing there.
         */
        $vite = [];

        if (app()->environment('local') && is_file(public_path('hot'))) {
            $origin = rtrim(trim((string) file_get_contents(public_path('hot'))), '/');
            $vite = [$origin, str_replace(['https://', 'http://'], ['wss://', 'ws://'], $origin)];
        }

        $allow = static fn (string $directive, string ...$sources): string => implode(
            ' ',
            [$directive, ...$sources, ...$vite],
        );

        return implode('; ', [
            "default-src 'self'",
            $allow('script-src', "'self'"),
            // The QR code on the enrollment screen is an inline SVG, and Blade emits a
            // little inline styling with it.
            $allow('style-src', "'self'", "'unsafe-inline'"),
            $allow('font-src', "'self'"),
            $allow('img-src', "'self'", 'data:'),
            $allow('connect-src', "'self'"),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
