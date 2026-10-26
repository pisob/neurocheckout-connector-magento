<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Community;

/** Wrap the legacy snapshot key with the platform key, independently of API rotation. */
final class SourceStorageKey
{
    public static function resolve(string $directory, array $configuration, callable $wrap, callable $unwrap): string
    {
        self::privatePath($directory, true);
        if (realpath($directory) !== $directory || !in_array($configuration['environment'], ['staging', 'production'], true)
            || !in_array($configuration['platform'], ['prestashop', 'magento'], true)
            || !is_int($configuration['nativeScope']) || $configuration['nativeScope'] < 1
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $configuration['shopId'])
            || !preg_match('/^[a-f0-9]{64}$/D', $configuration['secret'])) { self::fail(); }
        $binding = json_encode(['source-storage-v1', $configuration['platform'], $configuration['environment'],
            $configuration['nativeScope'], $configuration['shopId']], JSON_THROW_ON_ERROR);
        $path = $directory . '/storage-' . hash('sha256', $binding) . '.key';
        $lockPath = $path . '.lock';
        if (!file_exists($lockPath) && !is_link($lockPath)) {
            $created = @fopen($lockPath, 'x+b');
            if ($created) { chmod($lockPath, 0600); fclose($created); }
        }
        self::privatePath($lockPath, false);
        $lock = @fopen($lockPath, 'r+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            self::fail();
        }
        $temporary = null;
        try {
            if (!file_exists($path) && !is_link($path)) {
                // A lost key is an explicit failure; never replace it with the new API key.
                if (stream_get_contents($lock) !== '') { self::fail(); }
                self::verifyLegacySnapshot($directory, $configuration);
                $plain = json_encode(['binding' => $binding, 'secret' => $configuration['secret']], JSON_THROW_ON_ERROR);
                $encrypted = $wrap($plain);
                if (!is_string($encrypted) || $encrypted === '' || strlen($encrypted) > 4096
                    || $encrypted === $plain || strpos($encrypted, $configuration['secret']) !== false
                    || $unwrap($encrypted) !== $plain) { self::fail(); }
                $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $file = @fopen($temporary, 'x+b');
                if (!$file) { self::fail(); }
                try {
                    if (!chmod($temporary, 0600) || fwrite($file, $encrypted) !== strlen($encrypted) || !fflush($file)
                        || (function_exists('fsync') && !fsync($file))) { self::fail(); }
                } finally { fclose($file); }
                if (!@rename($temporary, $path)) { self::fail(); }
            }
            self::privatePath($path, false);
            $raw = file_get_contents($path, false, null, 0, 4097);
            if (!is_string($raw) || strlen($raw) > 4096) { self::fail(); }
            try { $value = json_decode($unwrap($raw), true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable $error) { self::fail(); }
            if (!is_array($value) || ($value['binding'] ?? null) !== $binding
                || !is_string($value['secret'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $value['secret'])) { self::fail(); }
            rewind($lock);
            if (fwrite($lock, "ready\n") !== 6 || !fflush($lock)
                || (function_exists('fsync') && !fsync($lock))) { self::fail(); }
            return $value['secret'];
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function verifyLegacySnapshot(string $directory, array $configuration): void
    {
        $binding = json_encode(['source-reconcile-v1', $configuration['platform'], $configuration['nativeScope'],
            $configuration['shopId']], JSON_THROW_ON_ERROR);
        if ($configuration['environment'] === 'production') { $binding .= ':production'; }
        $path = $directory . '/reconcile-' . hash('sha256', $binding) . '.bin';
        if (!file_exists($path) && !is_link($path)) { return; }
        self::privatePath($path, false);
        $raw = file_get_contents($path, false, null, 0, 33554433);
        if (!is_string($raw) || strlen($raw) < 29 || strlen($raw) > 33554432) { self::fail(); }
        $key = hash_hkdf('sha256', hex2bin($configuration['secret']), 32, 'source-reconcile-encryption-v1', $binding);
        if (openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), $binding) === false) { self::fail(); }
    }

    private static function privatePath(string $path, bool $directory): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
            || ($stat['mode'] & 0077) !== 0 || (!$directory && $stat['nlink'] !== 1)
            || !function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) { self::fail(); }
    }

    private static function fail(): void { throw new \RuntimeException('source_storage_key_unavailable'); }
}
