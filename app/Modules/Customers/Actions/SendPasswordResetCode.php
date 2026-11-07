<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerPasswordResetCode;
use App\Modules\Notifications\Support\MailketingClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPasswordResetCode
{
    public function __construct(
        private readonly MailketingClient $mailer,
    ) {}

    public function __invoke(Customer $customer): void
    {
        // Cooldown 60 detik: cegah email bombing dan kirim ganda.
        if (! Cache::add('customer-password-reset:'.$customer->id, true, 60)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($customer, $code): void {
            CustomerPasswordResetCode::query()->where('customer_id', $customer->id)->delete();

            CustomerPasswordResetCode::query()->create([
                'customer_id' => $customer->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        $this->deliver($customer, $code);
    }

    private function deliver(Customer $customer, string $code): void
    {
        if (! $this->mailer->configured()) {
            // Dev tanpa Mailketing: kode hanya ditulis di log lokal.
            if (app()->environment('local')) {
                Log::info('Kode reset password customer', [
                    'customer_id' => $customer->id,
                    'code' => $code,
                ]);
            }

            return;
        }

        try {
            $this->mailer->send(
                (string) $customer->email,
                'Kode reset password Pizza Fruzta',
                $this->html($customer, $code),
            );
        } catch (Throwable $exception) {
            // Respons API tetap generik; kegagalan provider cukup dicatat.
            report($exception);
        }
    }

    private function html(Customer $customer, string $code): string
    {
        $name = e($customer->name);

        return <<<HTML
        <html><body>
        <p>Halo {$name},</p>
        <p>Kode reset password Pizza Fruzta kamu:</p>
        <p style="font-size:28px;letter-spacing:6px"><strong>{$code}</strong></p>
        <p>Kode berlaku 10 menit. Abaikan email ini jika kamu tidak meminta reset.</p>
        </body></html>
        HTML;
    }
}
