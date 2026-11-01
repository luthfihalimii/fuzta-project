<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Actions\HandleBiteshipWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Biteship (tanpa auth, tanpa throttle — signature header = gerbang).
 *
 * Dashboard Biteship mengirim satu header custom yang kita tentukan:
 * nama header = `webhook_key`, isinya = `webhook_secret` (lihat docs
 * "Complete Guide to Understanding Biteship Webhooks"). Verifikasi memang
 * satu header `hash_equals` terhadap secret — bukan HMAC, bukan dua header.
 *
 * - header salah/hilang → 403 tanpa efek;
 * - `biteship_order_id` tidak diketahui → 404;
 * - payload tanpa order_id → 422;
 * - event valid → 200 (Biteship boleh mengulang event; handler idempotent).
 */
class BiteshipWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        HandleBiteshipWebhook $handle,
    ): JsonResponse {
        $key = (string) config('services.biteship.webhook_key');
        $secret = (string) config('services.biteship.webhook_secret');

        if ($key === '' || $secret === '' || ! hash_equals($secret, (string) $request->header($key))) {
            Log::warning('Biteship webhook signature tidak valid.');

            return response()->json(['message' => 'Signature tidak valid.'], 403);
        }

        $handle($request->json()->all());

        return response()->json(['message' => 'OK']);
    }
}
