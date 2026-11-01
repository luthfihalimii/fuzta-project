<?php

namespace App\Modules\Payments\Clients;

use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DOKU Checkout (Jokul, non-SNAP) via Http facade — tanpa SDK.
 *
 * Kontrak terverifikasi dari docs (developers.doku.com, November 2026):
 * - Endpoint: POST {base_url}/checkout/v1/payment
 * - Header: Client-Id, Request-Id, Request-Timestamp (ISO8601 UTC), Signature.
 * - Signature = HMACSHA256=base64(HMAC-SHA256(komponen, secret_key)); komponen
 *   "Client-Id:..\nRequest-Id:..\nRequest-Timestamp:..\nRequest-Target:..\nDigest:..",
 *   Digest = base64(SHA-256 raw JSON body). Request-Target = path endpoint DOKU
 *   (untuk outgoing) atau path Notification URL merchant (untuk webhook).
 * - Body basic: order.amount (int IDR), order.invoice_number (max 64),
 *   payment.payment_due_date (menit). Sukses: response.payment.url.
 */
class DokuClient
{
    private const TARGET = '/checkout/v1/payment';

    /**
     * Hitung signature DOKU non-SNAP (dipakai untuk request outgoing dan
     * verifikasi webhook incoming — format komponen sama di kedua arah).
     */
    public static function signature(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $requestTarget,
        string $rawBody,
        string $secretKey,
    ): string {
        $digest = base64_encode(hash('sha256', $rawBody, true));

        $components = sprintf(
            "Client-Id:%s\nRequest-Id:%s\nRequest-Timestamp:%s\nRequest-Target:%s\nDigest:%s",
            $clientId,
            $requestId,
            $timestamp,
            $requestTarget,
            $digest,
        );

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $components, $secretKey, true));
    }

    /**
     * Buat invoice checkout page. Body dikirim sebagai string agar byte
     * persis sama dengan byte yang di-hash untuk Digest.
     *
     * @return array{payment_url: string, invoice_number: string, expires_at: CarbonImmutable}
     *
     * @throws RuntimeException bila provider menolak/malformed
     */
    public function createPayment(Order $order): array
    {
        $clientId = (string) config('services.doku.client_id');
        $secretKey = (string) config('services.doku.secret_key');
        $baseUrl = (string) config('services.doku.base_url');
        $dueMinutes = (int) config('services.doku.payment_due_minutes', 30);

        if ($clientId === '' || $secretKey === '') {
            throw new RuntimeException('DOKU belum dikonfigurasi.');
        }

        $rawBody = json_encode([
            'order' => [
                'amount' => $order->total,
                'invoice_number' => $order->order_number,
            ],
            'payment' => [
                'payment_due_date' => $dueMinutes,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $requestId = (string) Str::uuid();
        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');

        $response = Http::baseUrl($baseUrl)
            ->timeout(10)
            ->connectTimeout(3)
            ->acceptJson()
            ->withBody($rawBody)
            ->withHeaders([
                'Client-Id' => $clientId,
                'Request-Id' => $requestId,
                'Request-Timestamp' => $timestamp,
                'Signature' => self::signature($clientId, $requestId, $timestamp, self::TARGET, $rawBody, $secretKey),
            ])
            ->post(self::TARGET);

        $url = $response->json('response.payment.url');

        if ($response->failed() || ! is_string($url) || $url === '') {
            // Jangan log body/header: rahasia & data customer tidak boleh ke log.
            throw new RuntimeException('DOKU create payment gagal (status '.(string) $response->status().').');
        }

        return [
            'payment_url' => $url,
            'invoice_number' => $order->order_number,
            // Docs juga memberi response.payment.expired_date (UTC+7,
            // yyyyMMddHHmmss); shortcut: pakai due date yang kita kirim, upgrade
            // ke expired_date bila DOKU menolak akumulasi due date.
            'expires_at' => now()->addMinutes($dueMinutes),
        ];
    }

    /**
     * Verifikasi signature webhook DOKU non-SNAP. Request-Target = path
     * Notification URL merchant (config), bukan host.
     */
    public function verifyNotification(Request $request): bool
    {
        $secretKey = (string) config('services.doku.secret_key');

        if ($secretKey === '') {
            return false;
        }

        $clientId = (string) $request->header('Client-Id');

        if ($clientId === '' || $clientId !== (string) config('services.doku.client_id')) {
            return false;
        }

        $rawBody = (string) $request->getContent();

        $expected = self::signature(
            $clientId,
            (string) $request->header('Request-Id'),
            (string) $request->header('Request-Timestamp'),
            (string) config('services.doku.notification_path', '/api/v1/webhooks/doku'),
            $rawBody,
            $secretKey,
        );

        return hash_equals($expected, (string) $request->header('Signature'));
    }
}
