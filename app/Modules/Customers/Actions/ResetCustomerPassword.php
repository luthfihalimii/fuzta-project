<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerPasswordResetCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ResetCustomerPassword
{
    private const INVALID = 'Kode reset tidak valid atau sudah kedaluwarsa.';

    public function __invoke(Customer $customer, string $code, string $password): void
    {
        // Bookkeeping percobaan harus commit walau respons akhirnya 422,
        // jadi error dilempar setelah transaksi selesai.
        $failed = DB::transaction(function () use ($customer, $code, $password): bool {
            $record = CustomerPasswordResetCode::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($record === null || $record->expires_at->isPast()) {
                $record?->delete();

                return true;
            }

            if (! Hash::check($code, $record->code_hash)) {
                $record->attempts++;

                if ($record->attempts >= 5) {
                    $record->delete();
                } else {
                    $record->save();
                }

                return true;
            }

            $customer->update(['password' => $password]);

            // Reset password mencabut semua sesi lama.
            $customer->tokens()->delete();
            CustomerPasswordResetCode::query()->where('customer_id', $customer->id)->delete();

            return false;
        });

        if ($failed) {
            throw ValidationException::withMessages(['code' => self::INVALID]);
        }
    }
}
