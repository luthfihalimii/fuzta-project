<?php

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use App\Modules\Notifications\Support\MailketingClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOperationalAlert
{
    public function __construct(
        private readonly MailketingClient $mailer,
    ) {}

    /**
     * @param  list<string>  $lines
     * @return int jumlah email terkirim
     */
    public function __invoke(string $subject, string $heading, array $lines): int
    {
        $recipients = User::query()
            ->where('role', Role::Owner->value)
            ->where('is_active', true)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        if (! $this->mailer->configured()) {
            Log::warning('Alert operasional tidak terkirim: Mailketing belum dikonfigurasi.', [
                'subject' => $subject,
                'lines' => $lines,
            ]);

            return 0;
        }

        $html = $this->html($heading, $lines);
        $sent = 0;

        foreach ($recipients as $email) {
            try {
                $this->mailer->send((string) $email, $subject, $html);
                $sent++;
            } catch (Throwable $exception) {
                // Satu penerima gagal tidak boleh membatalkan penerima lain.
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * @param  list<string>  $lines
     */
    private function html(string $heading, array $lines): string
    {
        $title = e($heading);
        $items = implode('', array_map(
            static fn (string $line): string => '<li>'.e($line).'</li>',
            $lines,
        ));

        return <<<HTML
        <html><body>
        <h2>{$title}</h2>
        <ul>{$items}</ul>
        <p>Pizza Fruzta</p>
        </body></html>
        HTML;
    }
}
