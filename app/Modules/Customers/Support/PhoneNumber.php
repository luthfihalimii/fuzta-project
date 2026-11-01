<?php

namespace App\Modules\Customers\Support;

class PhoneNumber
{
    /**
     * Normalisasi nomor ke format lokal `08...`
     * (buang spasi/tanda baca, `+62`/`62` jadi `0`).
     * Tidak memvalidasi — validasi tetap di Form Request.
     */
    public static function normalize(string $phone): string
    {
        $phone = str_replace([' ', '-', '(', ')', '.'], '', $phone);

        if (str_starts_with($phone, '+62')) {
            return '0'.substr($phone, 3);
        }

        if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }
}
