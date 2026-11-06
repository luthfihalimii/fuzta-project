<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginCustomer
{
    // Dummy bcrypt sah: supaya Hash::check tetap jalan saat nomor tidak
    // ditemukan (samakan waktu respons, jangan bocorkan keberadaan akun).
    private const DUMMY_PASSWORD_HASH = '$2y$12$/aAxBH6HaAliE8u/Yz2cGuArJ/FWmIohbn14KsDKYWF.sW4T76HI2';

    public function __construct(private readonly IssueCustomerToken $issueCustomerToken) {}

    /**
     * @return array{customer: Customer, token: string, expires_at: CarbonImmutable}
     */
    public function __invoke(string $phone, string $password): array
    {
        $customer = Customer::query()->where('phone', $phone)->first();
        $hash = $customer->password ?? self::DUMMY_PASSWORD_HASH;

        // Hash::check selalu dijalankan duluan (nomor tak ditemukan → dummy
        // hash): waktu respons tak boleh membocorkan keberadaan akun.
        $passwordOk = Hash::check($password, $hash);

        if ($customer === null || ! $passwordOk) {
            throw ValidationException::withMessages([
                'phone' => 'Nomor HP atau password salah.',
            ]);
        }

        // Dicek setelah verifikasi password: akun nonaktif tak boleh bisa
        // deteksi lewat pesan berbeda saat password salah.
        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'phone' => 'Akun tidak aktif.',
            ]);
        }

        $customer->forceFill(['last_login_at' => now()])->save();

        $issued = ($this->issueCustomerToken)($customer);

        return [
            'customer' => $customer,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
        ];
    }
}
