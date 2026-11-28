<?php
declare(strict_types=1);
namespace XcVm\Domain\Backup;

use XcVm\Core\Backup\{BackupIntegrity, BackupService};

/** Encrypted, read-back-verified delivery; failed copies are retried by the existing backup timer. */
final class OffsiteBackup {
    public static function status(): array {
        $file = MAIN_HOME . 'backups/offsite-status.json';
        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }

    public static function deliver(array $files, array $targets, callable $upload, callable $verify, callable $prune): array {
        $results = [];
        foreach ($targets as $target) {
            $error = null;
            foreach ($files as $name => $file) {
                try { $error = $upload($target, $file, $name) ?? $verify($target, $file, $name); }
                catch (\Throwable $e) { $error = 'OFFSITE_TRANSPORT_FAILED'; }
                if ($error !== null) break;
            }
            // Retention can only run after every required object has been verified.
            if ($error === null) { try { $prune($target,array_keys($files)); } catch (\Throwable $e) { $error = 'OFFSITE_RETENTION_FAILED'; } }
            $results[] = ['id'=>(int)$target['id'], 'state'=>$error === null ? 'verified' : 'failed', 'code'=>$error === null ? 'OK' : 'OFFSITE_DELIVERY_FAILED'];
        }
        return $results;
    }

    private static function record(string $dump, array $status): array {
        BackupIntegrity::writeJson($dump . '.offsite.json', $status);
        BackupIntegrity::writeJson(MAIN_HOME . 'backups/offsite-status.json', $status);
        $errorFile = $dump . '.error';
        $lines = is_file($errorFile) ? file($errorFile, FILE_IGNORE_NEW_LINES) : [];
        $lines = array_values(array_filter($lines ?: [], static fn($line)=>$line !== '' && !str_starts_with($line, '[offsite]')));
        if (in_array($status['state'], ['failed','blocked'], true)) $lines[] = '[offsite] ' . $status['code'];
        if ($lines) file_put_contents($errorFile, implode("\n", $lines)."\n", LOCK_EX); else @unlink($errorFile);
        return $status;
    }

    public static function run(string $dump, int $now): array {
        $previous = is_file($dump . '.offsite.json') ? (json_decode((string)file_get_contents($dump . '.offsite.json'), true) ?: []) : [];
        $status = ['state'=>'not_configured','time'=>$now,'file'=>basename($dump),'attempts'=>(int)($previous['attempts']??0)+1,'next_retry_at'=>0,'targets'=>[], 'code'=>'NO_OFFSITE_TARGET'];
        $targets = BackupTargets::enabled();
        if (!$targets) return self::record($dump, $status);
        $pass = RecoveryBundle::passphrase();
        $status['next_retry_at'] = $now + min(21600, 900 * (2 ** min(5, $status['attempts'] - 1)));
        if ($pass === null) return self::record($dump, array_replace($status, ['state'=>'blocked','code'=>'RECOVERY_PASSPHRASE_REQUIRED']));
        $archive = null;
        try {
            if (BackupIntegrity::inspect($dump) !== 'verified') throw new \RuntimeException('BACKUP_CHECKSUM_REQUIRED');
            $archive = $dump . '.' . bin2hex(random_bytes(6)) . '.horus';
            BackupArchive::encrypt($dump, $archive, $pass);
            $bundle = RecoveryBundle::make($now);
            if ($bundle === null) throw new \RuntimeException('RECOVERY_BUNDLE_FAILED');
            $files = [basename($dump).'.horus'=>$archive, basename($bundle)=>$bundle];
            $status['targets'] = self::deliver($files, $targets, [BackupTargets::class,'upload'], [BackupTargets::class,'verify'], [BackupTargets::class,'prune']);
            $success = !array_filter($status['targets'], static fn($r)=>$r['state'] !== 'verified');
            $status['state'] = $success ? 'success' : 'failed'; $status['code'] = $success ? 'OK' : 'OFFSITE_DELIVERY_FAILED';
            if ($success) $status['next_retry_at'] = 0;
            $status['encrypted'] = true; $status['bundle'] = basename($bundle);
            // Bound local configuration bundles independently of SQL retention.
            $bundles = glob(MAIN_HOME.'backups/recovery_*.bundle') ?: []; sort($bundles);
            foreach (array_slice($bundles, 0, max(0,count($bundles)-7)) as $old) if ($old !== $bundle) @unlink($old);
        } catch (\Throwable $e) {
            $status['state']='failed'; $status['code']='OFFSITE_PREPARATION_FAILED';
            error_log('Horus off-site backup preparation failed ('.get_class($e).').');
        } finally { if ($archive !== null && is_file($archive)) unlink($archive); sodium_memzero($pass); }
        return self::record($dump, $status);
    }

    /** Called under BackupIntegrity's lock even when the next local dump is not due. */
    public static function retry(int $now): void {
        $newest = BackupService::newestBackup();
        if ($newest === null) return;
        $dump = MAIN_HOME.'backups/'.$newest['filename'];
        $file = $dump.'.offsite.json';
        $status = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        if (in_array($status['state']??'', ['success'], true) || ($status['next_retry_at']??0)>$now) return;
        if (BackupTargets::enabled() !== []) {
            $result=self::run($dump,$now);
            $local=BackupIntegrity::status();
            if (($local['file']??'')===basename($dump) && ($local['state']??'')==='partial' && $result['state']==='success') {
                $local['state']=is_file($dump.'.error')?'partial':'success';
                BackupIntegrity::writeJson(MAIN_HOME.'backups/status.json',$local);
            }
        }
    }
}
