<?php
declare(strict_types=1);

final class ImageStorage
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_PIXELS = 20000000;
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'extension' => 'jpg'],
        IMAGETYPE_PNG => ['mime' => 'image/png', 'extension' => 'png'],
        IMAGETYPE_WEBP => ['mime' => 'image/webp', 'extension' => 'webp'],
    ];

    public static function storeUpload(mixed $upload): ?string
    {
        if ($upload === null) {
            return null;
        }
        if (!is_array($upload)
            || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || !is_int($upload['error'])
            || !is_string($upload['tmp_name'])
            || !is_int($upload['size'])) {
            throw new InvalidArgumentException('Choose a valid image file.');
        }
        if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::uploadErrorMessage($upload['error']));
        }
        if ($upload['size'] < 1 || $upload['size'] > self::MAX_BYTES) {
            throw new InvalidArgumentException('Images must be no larger than 2 MB.');
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('The uploaded image could not be verified.');
        }

        $image = @getimagesize($upload['tmp_name']);
        if ($image === false || !isset(self::IMAGE_TYPES[$image[2]])) {
            throw new InvalidArgumentException('Upload a valid JPEG, PNG, or WebP image.');
        }
        [$width, $height] = $image;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image dimensions must not exceed 20 megapixels.');
        }

        $imageType = self::IMAGE_TYPES[$image[2]];
        if (($image['mime'] ?? '') !== $imageType['mime']) {
            throw new InvalidArgumentException('The image file type could not be verified.');
        }

        $storageKey = bin2hex(random_bytes(16)) . '.' . $imageType['extension'];
        $directory = self::storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Image storage could not be initialized.');
        }

        if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $storageKey)) {
            throw new RuntimeException('The uploaded image could not be saved.');
        }

        return $storageKey;
    }

    public static function isValidKey(mixed $storageKey): bool
    {
        return is_string($storageKey)
            && preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/', $storageKey) === 1;
    }

    public static function pathForKey(string $storageKey): string
    {
        if (!self::isValidKey($storageKey)) {
            throw new InvalidArgumentException('The image reference is invalid.');
        }

        return self::storageDirectory() . DIRECTORY_SEPARATOR . $storageKey;
    }

    public static function delete(string $storageKey): void
    {
        if (self::isValidKey($storageKey)) {
            $path = self::pathForKey($storageKey);
            if (is_file($path) && !unlink($path)) {
                error_log('WInVote could not remove an unreferenced image: ' . $storageKey);
            }
        }
    }

    public static function url(?string $storageKey, string $basePath = '../'): ?string
    {
        if ($storageKey === null || !self::isValidKey($storageKey)) {
            return null;
        }

        return $basePath . 'image.php?key=' . rawurlencode($storageKey);
    }

    private static function storageDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'images';
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Images must be no larger than 2 MB.',
            UPLOAD_ERR_PARTIAL => 'The image upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Image uploads are temporarily unavailable.',
            UPLOAD_ERR_EXTENSION => 'The server rejected this image upload.',
            default => 'The image upload failed. Please choose another file.',
        };
    }
}
