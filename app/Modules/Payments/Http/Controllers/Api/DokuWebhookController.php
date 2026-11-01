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

        $orderData = $payload['order'] ?? [];
        $invoiceNumber = $orderData['invoice_number'] ?? null;
        $amountRaw = $orderData['amount'] ?? null;

        // Parsing tahan tipe: invoice_number harus string non-kosong, amount
        // hanya integer (penolakan desimal/eksponen/string) — 422, bukan 500.
        $invoice = is_string($invoiceNumber) ? trim($invoiceNumber) : '';

        $amount = null;

        if (is_int($amountRaw)) {
            $amount = $amountRaw;
        } elseif (is_string($amountRaw) && preg_match('/^\d+$/', trim($amountRaw)) === 1) {
            $amount = (int) trim($amountRaw);
        }

        if ($invoice === '' || $amount === null) {
            return response()->json(['message' => 'Pembayaran tidak valid.'], 422);
        }

        $settle($invoice, $amount);

        return response()->json(['message' => 'OK']);
    }
}
