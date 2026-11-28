<?php
declare(strict_types=1);
namespace XcVm\Domain\Backup;

/** Authenticated, bounded-memory encryption for SQL exports. No plaintext is published before verification. */
final class BackupArchive {
    public const MAGIC = "HORUSBKP1\n";
    private const CHUNK = 65536;

    private static function key(string $pass, string $salt): string {
        if (strlen($pass) < RecoveryBundle::MIN_LENGTH) throw new \RuntimeException('RECOVERY_PASSPHRASE_REQUIRED');
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $pass, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function read($file, int $length): string {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($file, $length - strlen($bytes));
            if ($part === false || $part === '') throw new \RuntimeException('BACKUP_TRUNCATED');
            $bytes .= $part;
        }
        return $bytes;
    }

    private static function write($file, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($file, substr($bytes, $offset));
            if (!$written) throw new \RuntimeException('BACKUP_WRITE_FAILED');
            $offset += $written;
        }
    }

    private static function frame($file, string &$state, string $bytes, int $tag): void {
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $bytes, self::MAGIC, $tag);
        self::write($file, pack('N', strlen($cipher)) . $cipher);
    }

    private static function stage(string $source, string $destination): array {
        if (!is_file($source) || is_link($source) || file_exists($destination) || is_link($destination)
            || !is_dir(dirname($destination)) || is_link(dirname($destination))) throw new \RuntimeException('INVALID_BACKUP_PATH');
        $input = fopen($source, 'rb');
        $path = tempnam(dirname($destination), '.horus-backup-');
        if (!$input || $path === false) throw new \RuntimeException('BACKUP_STAGE_FAILED');
        chmod($path, 0600);
        $output = fopen($path, 'wb');
        if (!$output) { fclose($input); unlink($path); throw new \RuntimeException('BACKUP_STAGE_FAILED'); }
        return [$input, $output, $path];
    }

    /** The output must not exist. Final publication uses an exclusive hard link. */
    public static function encrypt(string $source, string $destination, string $pass): array {
        [$input, $output, $stage] = self::stage($source, $destination);
        $key = null;
        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES); $key = self::key($pass, $salt);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            self::write($output, self::MAGIC . $salt . $header);
            $hash = hash_init('sha256'); $bytes = 0;
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if ($chunk === false) throw new \RuntimeException('BACKUP_READ_FAILED');
                if ($chunk === '') break;
                $bytes += strlen($chunk); hash_update($hash, $chunk);
                self::frame($output, $state, $chunk, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            }
            $metadata = ['name' => basename($source), 'bytes' => $bytes, 'sha256' => hash_final($hash)];
            self::frame($output, $state, json_encode($metadata, JSON_THROW_ON_ERROR), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (!fflush($output) || !link($stage, $destination)) throw new \RuntimeException('BACKUP_PUBLISH_FAILED');
            return $metadata;
        } finally {
            if ($key !== null) sodium_memzero($key);
            fclose($input); fclose($output); unlink($stage);
        }
    }

    public static function decrypt(string $source, string $destination, string $pass): array {
        [$input, $output, $stage] = self::stage($source, $destination);
        $key = null;
        try {
            if (self::read($input, strlen(self::MAGIC)) !== self::MAGIC) throw new \RuntimeException('INVALID_BACKUP_FORMAT');
            $salt = self::read($input, SODIUM_CRYPTO_PWHASH_SALTBYTES); $key = self::key($pass, $salt);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $hash = hash_init('sha256'); $bytes = 0;
            while (true) {
                $length = unpack('N', self::read($input, 4))[1];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) throw new \RuntimeException('INVALID_BACKUP_FRAME');
                $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::read($input, $length), self::MAGIC);
                if ($plain === false) throw new \RuntimeException('BACKUP_AUTHENTICATION_FAILED');
                [$chunk, $tag] = $plain;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $metadata = json_decode($chunk, true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($metadata) || ($metadata['bytes'] ?? null) !== $bytes
                        || !hash_equals(hash_final($hash), (string) ($metadata['sha256'] ?? '')) || fread($input, 1) !== '') throw new \RuntimeException('BACKUP_INTEGRITY_FAILED');
                    if (!fflush($output) || !link($stage, $destination)) throw new \RuntimeException('BACKUP_PUBLISH_FAILED');
                    return $metadata;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) throw new \RuntimeException('INVALID_BACKUP_TAG');
                self::write($output, $chunk); $bytes += strlen($chunk); hash_update($hash, $chunk);
            }
        } finally {
            if ($key !== null) sodium_memzero($key);
            fclose($input); fclose($output); unlink($stage);
        }
    }
}
