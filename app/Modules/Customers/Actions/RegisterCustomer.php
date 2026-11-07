<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class RegisterCustomer
{
    public function __invoke(string $name, string $email, string $phone, string $password): Customer
    {
        $phone = PhoneNumber::normalize($phone);
        $email = mb_strtolower(trim($email));

        try {
            return Customer::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'is_active' => true,
                'last_login_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Unique constraint adalah guard akhir: request sudah memvalidasi,
            // tapi dua register bersamaan masih mungkin lolos.
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'customers_phone_unique')) {
                throw ValidationException::withMessages([
                    'phone' => 'Nomor HP sudah terdaftar.',
                ]);
            }

            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'customers_email_unique')) {
                throw ValidationException::withMessages([
                    'email' => 'Email sudah terdaftar.',
                ]);
            }

            throw $exception;
        }
    }
}
