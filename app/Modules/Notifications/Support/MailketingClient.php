<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mailketing API v2 (POST /send). Satu request = satu penerima.
 */
class MailketingClient
{
    public function configured(): bool
    {
        $token = config('services.mailketing.token');
        $fromEmail = config('services.mailketing.from_email');

        return is_string($token) && $token !== ''
            && is_string($fromEmail) && $fromEmail !== '';
    }

    public function send(string $recipient, string $subject, string $html): void
    {
        $response = Http::withToken((string) config('services.mailketing.token'))
            ->acceptJson()
            ->timeout(15)
            ->post(rtrim((string) config('services.mailketing.base_url'), '/').'/send', [
                'from_name' => (string) config('services.mailketing.from_name'),
                'from_email' => (string) config('services.mailketing.from_email'),
                'recipient' => $recipient,
                'subject' => $subject,
                'content' => $html,
            ]);

        $response->throw();

        if ($response->json('success') !== true) {
            throw new RuntimeException('Mailketing menolak email: '.$response->body());
        }
    }
}
