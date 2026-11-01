<?php

namespace App\Http\Middleware;

use App\Modules\Customers\Models\CustomerToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomer
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $header = (string) $request->header('Authorization', '');

        // Header ada tapi rusak bukan tamu: selalu 401, jangan jatuh ke guest.
        if ($header !== '' && ! preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        if ($header === '') {
            if ($mode === 'optional') {
                return $next($request);
            }

            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        $token = CustomerToken::query()
            ->where('token_hash', hash('sha256', $matches[1]))
            ->where('expires_at', '>', now())
            ->with('customer')
            ->first();

        if (! $token || ! $token->customer?->is_active) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        $request->attributes->set('customer', $token->customer);
        // Token persisnya dipakai — agar logout mencabut baris ini saja.
        $request->attributes->set('customer_token', $token);

        // Maksimal 1x/jam: hindari write amplification di setiap request.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subHour())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }
}
