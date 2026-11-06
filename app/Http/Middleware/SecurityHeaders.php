<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()',
        );

        // Vite dev server beda origin; produksi tetap script-src 'self'.
        $isLocal = app()->environment('local');
        $scriptSrc = $isLocal
            ? "'self' 'unsafe-inline' http://localhost:* http://127.0.0.1:*"
            : "'self'";
        $connectSrc = $isLocal
            ? "'self' http: https: ws: wss:"
            : "'self' https: wss:";

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: https:",
            "font-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https:",
            "script-src {$scriptSrc}",
            "connect-src {$connectSrc}",
            "form-action 'self'",
        ]));

        // HSTS hanya berguna di HTTPS; produksi di belakang proxy TLS juga
        // harus mengirimnya walau request terlihat HTTP di aplikasi.
        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000',
            );
        }

        return $response;
    }
}
