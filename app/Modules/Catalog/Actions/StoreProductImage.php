<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class StoreProductImage
{
    public function __construct(
        private readonly ProcessProductImage $processImage,
    ) {}

    /**
     * Proses + simpan foto baru; mengembalikan key (misal products/3/a1b2c3d4e5.jpg).
     */
    public function store(UploadedFile $file, Product $product): string
    {
        $bytes = ($this->processImage)($file);

        $key = sprintf('products/%d/%s.jpg', $product->id, Str::random(10));

        Storage::disk(config('images.disk'))->put($key, $bytes);

        return $key;
    }

    /**
     * Hapus key lama dari disk; gagal = orphan (tidak fatal).
     */
    public function forget(?string $key): void
    {
        if ($key === null) {
            return;
        }

        try {
            Storage::disk(config('images.disk'))->delete($key);
        } catch (Throwable) {
            // orphan key; tidak merusak data — disk log sudah mencatat.
        }
    }
}
