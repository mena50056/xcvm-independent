<?php
declare(strict_types=1);
namespace XcVm\Core\Backup;

final class BackupIntegrity {
    public static function writeJson(string $path, array $data): void {
        $temp = tempnam(dirname($path), '.backup-meta-');
        if ($temp === false) throw new \RuntimeException('Cannot stage backup metadata');
        try {
            chmod($temp, 0600);
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $path)) {
                throw new \RuntimeException('Cannot save backup metadata');
            }
        } finally { if (is_file($temp)) unlink($temp); }
    }

    public static function seal(string $file): void {
        self::writeJson($file . '.sha256.json', [
            'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file), 'time' => time()
        ]);
    }

    /** Legacy dumps remain visible, but are not described as checksum verified. */
    public static function inspect(string $file): string {
        if (!is_file($file) || is_link($file) || filesize($file) === 0) return 'failed';
        if (!is_file($file . '.sha256.json')) return 'legacy';
        $data = json_decode((string) file_get_contents($file . '.sha256.json'), true);
        if (!is_array($data) || !isset($data['sha256'], $data['bytes'])) return 'failed';
        clearstatcache(true, $file);
        return (int) $data['bytes'] === filesize($file) && hash_equals((string) $data['sha256'], hash_file('sha256', $file)) ? 'verified' : 'failed';
    }

    public static function status(): array {
        $path = MAIN_HOME . 'backups/status.json';
        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    /** A single lock serializes scheduled/manual runs and restore operations. */
    public static function lock() {
        $directory = MAIN_HOME . 'backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create backup directory');
        $lock = fopen($directory . '/.operation.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return false;
        }
        chmod($directory . '/.operation.lock', 0600);
        return $lock;
    }
}
