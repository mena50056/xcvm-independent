<?php
require dirname(__DIR__, 2) . '/src/vendor/autoload.php';
use XcVm\Core\Backup\BackupIntegrity;
use XcVm\Core\Backup\IndependentDatabaseDump;

function checkBackup(bool $passed, string $name): void {
    if (!$passed) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$directory = sys_get_temp_dir() . '/horus-backups-' . bin2hex(random_bytes(5));
mkdir($directory, 0700);
define('MAIN_HOME', $directory . '/');
mkdir($directory . '/backups', 0700);
$file = $directory . '/backups/backup_test.sql';
file_put_contents($file, '-- old SQL');
checkBackup(BackupIntegrity::inspect($file) === 'legacy', 'Older backups remain recognizable');
BackupIntegrity::seal($file);
checkBackup(BackupIntegrity::inspect($file) === 'verified', 'New checksum verifies original bytes');
file_put_contents($file, '-- changed SQL');
checkBackup(BackupIntegrity::inspect($file) === 'failed', 'Changed backup is detected');
checkBackup(!IndependentDatabaseDump::restore([], $file), 'Corrupt file rejected before any database process');
BackupIntegrity::writeJson($directory . '/backups/status.json', ['state'=>'failed']);
checkBackup(BackupIntegrity::status()['state'] === 'failed', 'Failure status survives a new request');
$lock = BackupIntegrity::lock();
checkBackup(is_resource($lock) && BackupIntegrity::lock() === false, 'Concurrent operations are refused');
fclose($lock);
$lock = BackupIntegrity::lock();
checkBackup(is_resource($lock), 'Operation lock is released');
fclose($lock);
foreach (glob($directory . '/backups/*') as $path) unlink($path);
unlink($directory . '/backups/.operation.lock');
rmdir($directory . '/backups'); rmdir($directory);
