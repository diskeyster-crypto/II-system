<?php
declare(strict_types=1);

namespace DevBridge\Core;

/**
 * Symmetric encryption using AES-256-GCM.
 * Key is derived from app_secret in config.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private static function key(): string
    {
        $cfg = require dirname(__DIR__, 2) . '/config/config.php';
        return hash('sha256', $cfg['app_secret'], true); // 32 bytes
    }

    public static function encrypt(string $plaintext): string
    {
        $iv         = random_bytes(12);
        $tag        = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 12 + self::TAG_LEN) {
            return '';
        }
        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, self::TAG_LEN);
        $ciphertext = substr($raw, 12 + self::TAG_LEN);
        $plain      = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain !== false ? $plain : '';
    }

    /** Returns masked version safe to display in UI (first 4 chars + asterisks). */
    public static function mask(string $plaintext): string
    {
        if (strlen($plaintext) <= 4) {
            return '****';
        }
        return substr($plaintext, 0, 4) . str_repeat('*', min(12, strlen($plaintext) - 4));
    }
}
