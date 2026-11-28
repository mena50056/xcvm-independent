<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);

// Only the databases selected by the administrator, from their MaxMind account.
// No third-party fallback, ASN catalogue import, customer writes or stream controls.
if (!empty(XC_VM::config_server()['is_lb'])) exit(1);
$pdo=XC_VM::db_connect();
$settings=$pdo->query('SELECT maxmind_account_id,maxmind_license_key,maxmind_editions FROM settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$selected=json_decode((string)($settings['maxmind_editions']??'[]'),true);
$selected=array_values(array_intersect(is_array($selected)?$selected:[],['GeoLite2-Country','GeoLite2-City','GeoIP2-ISP']));
$settings['maxmind_editions']=json_encode($selected);
$updater=\XcVm\Core\GeoIP\MaxMindUpdater::fromSettings($settings);
if ($updater===null) { echo "MaxMind account or editions are not configured; skipped.\n"; exit(0); }
$lock=fopen(BIN_PATH.'maxmind/.horus-update.lock','c');
if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) { echo "A GeoIP update is already running; skipped.\n"; exit(0); }
try {
    $failed=false;
    foreach ($updater->update(false) as $result) {
        $failed=$failed||$result['error']!==null;
        echo $result['edition'].': '.($result['error']??($result['updated']?'updated':'unchanged'))."\n";
    }
} finally { flock($lock,LOCK_UN); fclose($lock); }
exit($failed?1:0);
