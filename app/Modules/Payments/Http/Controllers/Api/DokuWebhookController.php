<?php

namespace App\Modules\Payments\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Actions\SettleDokuPayment;
use App\Modules\Payments\Clients\DokuClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook DOKU (tanpa auth, tanpa throttle — signature = gerbang).
 *
 * - signature salah → 403 tanpa efek;
 * - SUCCESS → SettleDokuPayment (idempotent; nominal salah → 422,
 *   invoice tak dikenal → 422 — DOKU berhenti retry pada 4xx);
 * - FAILED/EXPIRED/status lain → 200 tanpa efek (untuk Checkout non-SNAP
 *   customer boleh mencoba channel lain; hanya SUCCESS yang melunasi);
 * - error DB asli → 500 (DOKU akan retry notifikasi).
 */
class DokuWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        DokuClient $client,
        SettleDokuPayment $settle,
    ): JsonResponse {
        if (! $client->verifyNotification($request)) {
            Log::warning('DOKU webhook signature tidak valid.');

            return response()->json(['message' => 'Signature tidak valid.'], 403);
        }

        $payload = $request->json()->all();
        $status = (string) ($payload['transaction']['status'] ?? '');

        if ($status !== 'SUCCESS') {
            return response()->json(['message' => 'OK']);
        }

        $invoiceNumber = (string) ($payload['order']['invoice_number'] ?? '');
        $amount = $payload['order']['amount'] ?? null;

        if ($invoiceNumber === '' || ! is_numeric($amount)) {
            return response()->json(['message' => 'Nominal pembayaran tidak valid.'], 422);
        }

        $settle($invoiceNumber, (int) $amount);

        return response()->json(['message' => 'OK']);
    }
}
