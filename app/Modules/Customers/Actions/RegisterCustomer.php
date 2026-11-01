<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class RegisterCustomer
{
    public function __invoke(string $name, string $phone, string $password): Customer
    {
        $phone = PhoneNumber::normalize($phone);

        try {
            return Customer::query()->create([
                'name' => $name,
                'phone' => $phone,
                'password' => $password,
                'is_active' => true,
                'last_login_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Unique constraint `phone` adalah guard akhir: request sudah
            // memvalidasi, tapi dua register bersamaan masih mungkin lolos.
            if (($exception->errorInfo[0] ?? null) === '23505'
                && str_contains($exception->getMessage(), 'customers_phone_unique')) {
                throw ValidationException::withMessages([
                    'phone' => 'Nomor HP sudah terdaftar.',
                ]);
            }

            throw $exception;
        }
    }
}
