<?php

namespace XcVm\Domain\Backup;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Localization\Translator;

/**
 * The recovery bundle: what a database dump leaves out and a new MAIN needs —
 * config.ini (the database credentials), the modules' configuration, the
 * custom translations (config/lang/) and, when the cluster API is set up, the
 * cluster's key export — encrypted with a
 * passphrase (Argon2id, then XSalsa20-Poly1305). Each off-site export pairs
 * encrypted SQL with a fresh bundle; `backup:open-bundle` opens one.
 *
 * The passphrase is kept in config/backup_bundle.pass (xc_vm only), never in
 * the database, so no dump carries it; keep a copy of it off this machine.
 * The independent runtime includes its protected state and master key together.
 * Vendor-specific machine-sealed files remain outside the legacy bundle.
 *
 * @package XC_VM_Domain_Backup
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class RecoveryBundle {
	public const MAGIC = "XCVMBNDL1\n";

	/** The configuration files it holds, under CONFIG_PATH (a bundle made before may also hold bundled_modules.php). */
	public const FILES = ['config.ini', 'modules.php'];

	/** A passphrase this long at least (the cluster key export asks as much). */
	public const MIN_LENGTH = 20;

	public static function passphraseFile(): string {
		return CONFIG_PATH . 'backup_bundle.pass';
	}

	public static function passphrase(): ?string {
		if (is_link(self::passphraseFile()) || !is_file(self::passphraseFile()) || filesize(self::passphraseFile())>4096) return null;
		$rPass = @file_get_contents(self::passphraseFile());
		return is_string($rPass) && strlen(trim($rPass)) >= self::MIN_LENGTH ? trim($rPass) : null;
	}

	/** Set (or with '', clear) the passphrase. False: too short. */
	public static function setPassphrase(string $rPass): bool {
		$rPass = trim($rPass);
		if (is_link(self::passphraseFile())) return false;
		if ($rPass === '') {
			@unlink(self::passphraseFile());
			return true;
		}
		if (strlen($rPass) < self::MIN_LENGTH || strlen($rPass)>4096) {
			return false;
		}
		$rFile = self::passphraseFile();
		$rTemp = tempnam(dirname($rFile), '.recovery-key-');
		if ($rTemp===false) return false;
		try { chmod($rTemp,0600);return file_put_contents($rTemp,$rPass)===strlen($rPass) && rename($rTemp,$rFile); }
		finally { if(is_file($rTemp))unlink($rTemp); }
	}

	/**
	 * The bundle's files: the configuration files, the custom translations
	 * (as `lang/<code>.ini`, where they lie under config/), and the cluster key
	 * export when the cluster API is set up (it can be refused: the bundle goes
	 * without it).
	 *
	 * @return array<string, string> name => content
	 */
	public static function files(string $rPass): array {
		$rFiles = [];
		if (defined('XCVM_INDEPENDENT_LAB') && XCVM_INDEPENDENT_LAB) {
			$rState = (string) getenv('XCVM_LAB_STATE');
			if ($rState === '') throw new \RuntimeException('RECOVERY_STATE_REQUIRED');
			$rFiles = IndependentRecovery::files($rState, rtrim(CONFIG_PATH, '/'));
		}
		foreach (self::FILES as $rName) {
			if (is_readable(CONFIG_PATH . $rName)) {
				$rFiles[$rName] = (string) file_get_contents(CONFIG_PATH . $rName);
			}
		}
		// What an administrator uploaded or edited under Settings > Translations: no dump holds it.
		$rLang = Translator::customDir();
		foreach ($rLang === '' ? [] : (glob($rLang . '*.ini') ?: []) as $rFile) {
			$rFiles['lang/' . basename($rFile)] = (string) file_get_contents($rFile);
		}
		try {
			if (class_exists(ClusterCryptoFactory::class) && ClusterCryptoFactory::available()) {
				$rFiles['cluster-keys.export'] = ClusterCryptoFactory::create()->exportKeys($rPass);
			}
		} catch (\Throwable) {
			// No cluster root here, or the export refused: the files still go.
		}
		return $rFiles;
	}

	/** @param array<string, string> $rFiles */
	public static function seal(array $rFiles, string $rPass, int $rNow): string {
		$rSalt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$rKey = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $rPass, $rSalt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$rNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$rPlain = (string) json_encode(['made' => $rNow, 'files' => array_map('base64_encode', $rFiles)]);
		$rSealed = self::MAGIC . $rSalt . $rNonce . sodium_crypto_secretbox($rPlain, $rNonce, $rKey);
		sodium_memzero($rKey);
		return $rSealed;
	}

	/**
	 * Open a bundle.
	 *
	 * @return array{made: int, files: array<string, string>}|null null: not a bundle, or the wrong passphrase
	 */
	public static function open(string $rBundle, string $rPass): ?array {
		$rHead = strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if (!str_starts_with($rBundle, self::MAGIC) || strlen($rBundle) <= $rHead) {
			return null;
		}
		$rSalt = substr($rBundle, strlen(self::MAGIC), SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$rNonce = substr($rBundle, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$rKey = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $rPass, $rSalt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$rPlain = sodium_crypto_secretbox_open(substr($rBundle, $rHead), $rNonce, $rKey);
		sodium_memzero($rKey);
		$rData = $rPlain === false ? null : json_decode($rPlain, true);
		if (!is_array($rData) || !is_array($rData['files'] ?? null)) {
			return null;
		}
		return ['made' => (int) ($rData['made'] ?? 0), 'files' => array_map(static fn($rValue): string => (string) base64_decode((string) $rValue, true), $rData['files'])];
	}

	/** Write today's bundle to backups/recovery_<date>.bundle; null without a passphrase or when it cannot be written. */
	public static function make(int $rNow): ?string {
		$rPass = self::passphrase();
		if ($rPass === null) {
			return null;
		}
		$rFile = MAIN_HOME . 'backups/recovery_' . date('Y-m-d_H-i-s', $rNow) . '_' . bin2hex(random_bytes(3)) . '.bundle';
		$rStage = tempnam(MAIN_HOME . 'backups', '.recovery-');
		if ($rStage === false) return null;
		try {
			chmod($rStage, 0600);
			$rSealed = self::seal(self::files($rPass), $rPass, $rNow);
			if (file_put_contents($rStage, $rSealed) !== strlen($rSealed) || !link($rStage, $rFile)) return null;
			return $rFile;
		} finally { unlink($rStage); }
	}

	/** Extract only to a new directory; validate every name before creating any output. */
	public static function extract(array $rOpened, string $rDir): void {
		if (file_exists($rDir) || is_link($rDir)) throw new \RuntimeException('RECOVERY_DESTINATION_EXISTS');
		foreach ($rOpened['files'] as $rName => $rBytes) {
			if (!preg_match('~^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$~D', (string) $rName)
				|| array_intersect(explode('/', (string) $rName), ['.', '..'])) throw new \RuntimeException('INVALID_RECOVERY_PATH');
		}
		if (!mkdir($rDir, 0700, true)) throw new \RuntimeException('RECOVERY_DIRECTORY_FAILED');
		foreach ($rOpened['files'] as $rName => $rBytes) {
			$rPath = $rDir . '/' . $rName;
			if (!is_dir(dirname($rPath)) && !mkdir(dirname($rPath), 0700, true)) throw new \RuntimeException('RECOVERY_DIRECTORY_FAILED');
			$rFile = fopen($rPath, 'xb');
			if (!$rFile) throw new \RuntimeException('RECOVERY_WRITE_FAILED');
			try { chmod($rPath, 0600); if (fwrite($rFile, $rBytes) !== strlen($rBytes)) throw new \RuntimeException('RECOVERY_WRITE_FAILED'); }
			finally { fclose($rFile); }
		}
	}

	/** The newest local bundle's time, or 0. */
	public static function newest(): int {
		$rFiles = glob(MAIN_HOME . 'backups/recovery_*.bundle') ?: [];
		return $rFiles === [] ? 0 : max(array_map('filemtime', $rFiles));
	}
}
