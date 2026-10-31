<?php

namespace App\Modules\Catalog\Actions;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ProcessProductImage
{
    private const int MAX_WIDTH = 1200;

    private const int JPEG_QUALITY = 82;

    private const string INVALID_IMAGE_MESSAGE = 'File gambar rusak atau format tidak didukung.';

    /**
     * Re-encode upload sebagai JPEG (lebar <= 1200px, quality 82, latar putih),
     * EXIF orientation diterapkan (foto dari HP sering sideways).
     *
     * @throws ValidationException 422 jika file gagal didecode/encode
     */
    public function __invoke(UploadedFile $file): string
    {
        $image = @imagecreatefromstring($file->get());

        if ($image === false) {
            throw $this->invalidImage();
        }

        $path = $file->getRealPath();
        $exif = $path === false ? false : @exif_read_data($path);
        $orientation = $exif !== false && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        $image = $this->applyOrientation($image, $orientation, $exif !== false);

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::MAX_WIDTH) {
            $targetHeight = max(1, (int) round($height * self::MAX_WIDTH / $width));

            $resized = imagecreatetruecolor(self::MAX_WIDTH, $targetHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, self::MAX_WIDTH, $targetHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        // PNG/WebP ber-alfa jadi latar hitam saat di-jpeg; flatten putih satu titik sebelum encode.
        $image = $this->flattenOnWhite($image);

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        if ($bytes === '') {
            throw $this->invalidImage();
        }

        return $bytes;
    }

    /**
     * Terapkan transformasi EXIF orientation 1-8 (konvensi sama dengan
     * PIL.ImageOps.exif_transpose / browser: 5 = transpose, 7 = transverse).
     * Orientation 1 (atau tanpa EXIF) mengembalikan gambar apa adanya.
     *
     * @throws ValidationException 422 jika GD gagal memproses (misal OOM)
     */
    public function applyOrientation(GdImage $image, int $orientation, bool $hasExif = true): GdImage
    {
        if (! $hasExif) {
            return $image;
        }

        $oriented = match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90, 0),
            6 => imagerotate($image, 270, 0),
            7 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), 270, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (! $oriented instanceof GdImage) {
            throw $this->invalidImage();
        }

        return $oriented;
    }

    /**
     * shortcut: sebagian build GD memodifikasi gambar in-place dan mengembalikan true;
     * upgrade: hapus shim jika semua target PHP menjalani GD upstream yang selalu mengembalikan GdImage baru.
     */
    private function flip(GdImage $image, int $mode): GdImage
    {
        $flipped = imageflip($image, $mode);

        return $flipped instanceof GdImage ? $flipped : $image;
    }

    private function flattenOnWhite(GdImage $image): GdImage
    {
        $dst = imagecreatetruecolor(imagesx($image), imagesy($image));
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, imagesx($image) - 1, imagesy($image) - 1, $white);
        imagecopy($dst, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $dst;
    }

    private function invalidImage(): ValidationException
    {
        return ValidationException::withMessages([
            'image' => self::INVALID_IMAGE_MESSAGE,
        ]);
    }
}
