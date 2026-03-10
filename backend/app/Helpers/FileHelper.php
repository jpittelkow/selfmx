<?php

namespace App\Helpers;

class FileHelper
{
    /**
     * Convert bytes to a human-readable size string.
     */
    public static function humanReadableSize(int $bytes, int $precision = 2): string
    {
        if ($bytes < 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $precision) . ' ' . $units[$index];
    }

    /**
     * Get MIME type from a file path using PHP's built-in detection.
     */
    public static function detectMimeType(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return $mime ?: null;
    }

    /**
     * Get a safe filename by removing potentially dangerous characters.
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove path separators and null bytes
        $filename = str_replace(['/', '\\', "\0"], '', $filename);

        // Remove leading dots (hidden files)
        $filename = ltrim($filename, '.');

        // Replace multiple spaces/dashes with single
        $filename = preg_replace('/[\s]+/', ' ', $filename);

        return trim($filename) ?: 'unnamed';
    }

    /**
     * Get file extension from a filename (lowercase).
     */
    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Check if a MIME type matches an allowed list.
     */
    public static function isMimeTypeAllowed(string $mimeType, array $allowedTypes): bool
    {
        foreach ($allowedTypes as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }
            // Support wildcard like "image/*"
            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
