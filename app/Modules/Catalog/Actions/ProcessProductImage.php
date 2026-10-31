<?php

namespace App\Modules\Catalog\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ProcessProductImage
{
    private const int MAX_WIDTH = 1200;

    private const int JPEG_QUALITY = 82;

    /**
     * Re-encode upload sebagai JPEG (lebar <= 1200px, quality 82),
     * EXIF orientation diterapkan (foto dari HP sering sideways).
     *
     * @throws ValidationException 422 jika file tak bisa didecode
     */
    public function __invoke(UploadedFile $file): string
    {
        $image = @imagecreatefromstring($file->get());

        if ($image === false) {
            throw ValidationException::withMessages([
                'image' => 'File gambar rusak atau format tidak didukung.',
            ]);
        }

        $image = $this->applyExifOrientation($image, $file);

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::MAX_WIDTH) {
            $targetHeight = max(1, (int) round($height * self::MAX_WIDTH / $width));

            $resized = imagecreatetruecolor(self::MAX_WIDTH, $targetHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, self::MAX_WIDTH, $targetHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, UploadedFile $file)
    {
        $exif = @exif_read_data($file->getRealPath());

        if ($exif === false || ! isset($exif['Orientation'])) {
            return $image;
        }

        // shortcut: mapping standar 8 orientation EXIF; format non-EXIF tak terpengaruh.
        return match ((int) $exif['Orientation']) {
            8 => imagerotate($image, 270),
            3 => imagerotate($image, 180),
            6 => imagerotate($image, 90),
            2 => imagemirror($image),
            5 => imagerotate(imagemirror($image), 270),
            7 => imagerotate(imagemirror($image), 90),
            4 => imagerotate(imagetranspose($image), 180),
            default => $image,
        };
    }
}
