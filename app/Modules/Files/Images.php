<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Core\ApiError;

/**
 * Uploaded images: typed by their bytes, refused above 40 megapixels before
 * anything decodes them, and — where GD exists — decoded once and re-encoded,
 * so what is stored is pixels the server drew rather than whatever the upload
 * carried. Never decoded again on request, never from a URL.
 */
final class Images
{
    public const MAX_PIXELS = 40_000_000;

    /** @return string the stored file name (random, with the right extension) */
    public static function store(string $source, string $clientName, string $dir, int $maxSide = 2048): string
    {
        $type = UploadTypes::uploadType($clientName, 'image');
        if ($type === null || !UploadTypes::bytesMatch($source, $type['ext'])) {
            throw new ApiError(UploadTypes::refusal('image'), 415);
        }
        $info = @getimagesize($source);
        if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ApiError('That image is too large (over 40 megapixels).', 413);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The media folder is not writable.');
        }
        $ext = $type['ext'] === 'jpeg' ? 'jpg' : $type['ext'];
        $name = bin2hex(random_bytes(12));
        if (extension_loaded('gd')) {
            $image = match ($ext) {
                'jpg' => @imagecreatefromjpeg($source),
                'png' => @imagecreatefrompng($source),
                'gif' => @imagecreatefromgif($source),
                'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
                default => false,
            };
            if ($image === false) {
                throw new ApiError('That image could not be read.', 415);
            }
            [$w, $h] = [imagesx($image), imagesy($image)];
            $scale = min(1, $maxSide / max($w, $h));
            if ($scale < 1) {
                $resized = imagescale($image, (int) round($w * $scale), (int) round($h * $scale));
                if ($resized !== false) {
                    imagedestroy($image);
                    $image = $resized;
                }
            }
            // Transparency survives only in PNG; everything else becomes a JPEG.
            $keepAlpha = in_array($ext, ['png', 'gif', 'webp'], true);
            $ext = $keepAlpha ? 'png' : 'jpg';
            $target = "$dir/$name.$ext";
            if ($keepAlpha) {
                imagesavealpha($image, true);
                imagepng($image, $target, 8);
            } else {
                imagejpeg($image, $target, 86);
            }
            imagedestroy($image);
        } else {
            $target = "$dir/$name.$ext";
            if (!@copy($source, $target)) {
                throw new \RuntimeException('Could not store the image.');
            }
        }
        @chmod($target, 0644);
        return "$name.$ext";
    }
}
