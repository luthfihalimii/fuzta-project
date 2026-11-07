<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien FCM HTTP v1. Kredensial = service account JSON; token OAuth2
 * dibuat sendiri (JWT RS256) agar tidak menambah dependency.
 */
class FcmClient
{
    public function configured(): bool
    {
        $projectId = config('services.fcm.project_id');
        $path = config('services.fcm.credentials_path');

        return is_string($projectId) && $projectId !== ''
            && is_string($path) && $path !== '' && is_file($path);
    }

    /**
     * @param  array<string, string>  $data
     * @return bool false jika FCM menyatakan token device tidak valid lagi.
     */
    public function send(string $deviceToken, string $title, string $body, array $data): bool
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->endpoint(), [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                ],
            ]);

        if ($this->isInvalidToken($response)) {
            return false;
        }

        $response->throw();

        return true;
    }

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send';
    }

    private function accessToken(): string
    {
        $path = (string) config('services.fcm.credentials_path');
        $cacheKey = 'fcm.access_token.'.hash('sha256', $path);

        // Token Google berlaku 1 jam; simpan 50 menit agar tidak kedaluwarsa.
        return Cache::remember($cacheKey, 3000, function () use ($path): string {
            $credentials = json_decode((string) file_get_contents($path), true);

            if (! is_array($credentials)) {
                throw new RuntimeException('Service account FCM bukan JSON objek.');
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt($credentials),
            ]);

            $response->throw();

            return (string) $response->json('access_token');
        });
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function signedJwt(array $credentials): string
    {
        $clientEmail = $credentials['client_email'] ?? null;
        $privateKey = $credentials['private_key'] ?? null;

        if (! is_string($clientEmail) || ! is_string($privateKey)) {
            throw new RuntimeException('Service account FCM tanpa client_email/private_key.');
        }

        $now = time();
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url((string) json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $input = $header.'.'.$claims;
        $signature = '';

        if (openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new RuntimeException('Gagal menandatangani JWT FCM.');
        }

        return $input.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function isInvalidToken(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        if ($response->status() !== 400) {
            return false;
        }

        $body = $response->json();

        return is_array($body)
            && in_array(data_get($body, 'error.details.0.errorCode'), ['UNREGISTERED', 'INVALID_ARGUMENT'], true);
    }
}
