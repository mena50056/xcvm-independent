<?php
declare(strict_types=1);
// Recovery must work before a MAIN can boot or reach its original database.
require dirname(__DIR__).'/panel/src/vendor/autoload.php';
use XcVm\Domain\Backup\{BackupArchive,RecoveryBundle};
if (PHP_SAPI !== 'cli' || $argc !== 3) { fwrite(STDERR,"Usage: php deployment/open-backup.php <encrypted SQL or bundle> <new output file or directory> < private-passphrase-file\n");exit(1); }
$pass=trim((string)stream_get_contents(STDIN));
try {
    $file=fopen($argv[1],'rb');if(!$file)throw new RuntimeException();$magic=fread($file,strlen(BackupArchive::MAGIC));fclose($file);
    if($magic===BackupArchive::MAGIC) BackupArchive::decrypt($argv[1],$argv[2],$pass);
    else { $opened=RecoveryBundle::open((string)file_get_contents($argv[1]),$pass);if($opened===null)throw new RuntimeException();RecoveryBundle::extract($opened,$argv[2]); }
    echo "Backup authenticated and extracted into a new destination. No running panel or database was changed.\n";
} catch (Throwable $e) { fwrite(STDERR,"Cannot open backup: verify the passphrase, complete file and unused destination.\n");exit(1); }
finally { sodium_memzero($pass); }
